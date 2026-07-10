#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# ephpm/wordpress-worker — WooCommerce lifecycle regression harness.
#
# Regression for the "boot-once actions never re-fire" bug: WooCommerce's
# WC_Form_Handler::add_to_cart_action() is registered on `wp_loaded`. Under
# classic FPM that fires per request against the request's $_GET, so
# GET /?add-to-cart=<id> adds the product to the cart + sets a
# wp_woocommerce_session_* cookie. Under a naive persistent worker, wp_loaded
# only fires ONCE at boot (against empty $_GET) and the request is a silent
# no-op: 200 storefront, empty cart, no session cookie.
#
# The five gates below are exactly what an external reporter specified:
#   1. GET /?add-to-cart=<id>   — 302 + wp_woocommerce_session_* Set-Cookie
#   2. GET /wp-json/wc/store/v1/cart with that cookie — cart contains product
#   3. Two-user isolation      — jar A adds product P1, jar B adds product P2;
#                                neither cart sees the other's item
#   4. Golden-path preserved   — homepage + REST root still 200 (regression
#                                against the extra per-request work breaking
#                                other paths)
#   5. Per-request hook cost   — measure elapsed ms for a probe that reads
#                                `did_action('init')` / `did_action('wp_loaded')`
#                                to prove the re-fire actually happens, and
#                                report the wall-clock cost
#
# Exit 0 iff all five pass.
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PKG_ROOT="$(cd "${HERE}/.." && pwd)"
IMAGE="ephpm-wp-worker-wc-e2e:latest"
CONTAINER="ephpm-wp-worker-wc-e2e"
BASE_IMAGE="localhost/ephpm:worker-main"
PORT="${EPHPM_WC_E2E_PORT:-8110}"
BASE="http://127.0.0.1:${PORT}"

PODMAN="${PODMAN:-podman}"

# --unfixed swaps in worker-unfixed.php (no runRequestLifecycle() call) so we
# can reproduce the pre-fix failure mode against the same built image.
MODE="fixed"
if [[ "${1:-}" == "--unfixed" ]]; then
    MODE="unfixed"
    CONTAINER="${CONTAINER}-unfixed"
    PORT=$((PORT + 1))
    BASE="http://127.0.0.1:${PORT}"
fi

pass_count=0
fail_count=0
declare -A RESULT

note()  { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
ok()    { printf '  \033[32mPASS\033[0m %s\n' "$*"; }
bad()   { printf '  \033[31mFAIL\033[0m %s\n' "$*"; }

record() { # name  0|1(pass=0)  message
    local name="$1" code="$2" msg="${3:-}"
    if [[ "$code" == "0" ]]; then
        RESULT[$name]="PASS"; pass_count=$((pass_count+1)); ok "${name}: ${msg}"
    else
        RESULT[$name]="FAIL"; fail_count=$((fail_count+1)); bad "${name}: ${msg}"
    fi
}

cleanup() {
    ${PODMAN} rm -f "${CONTAINER}" >/dev/null 2>&1 || true
    rm -rf "${HERE}/pkgsrc" >/dev/null 2>&1 || true
}
trap cleanup EXIT

# ── 0. Base image check. ─────────────────────────────────────────────────────
note "Checking base image ${BASE_IMAGE}"
if ! ${PODMAN} image exists "${BASE_IMAGE}"; then
    echo "Base image ${BASE_IMAGE} not found. Retag your ephpm runtime image, e.g.:"
    echo "  podman tag docker.io/ephpm/ephpm:v0.4.0-php8.4 localhost/ephpm:worker-main"
    exit 2
fi

# ── 1. Stage the adapter source (path composer repo). ────────────────────────
note "Staging adapter source into e2e/pkgsrc"
rm -rf "${HERE}/pkgsrc"
mkdir -p "${HERE}/pkgsrc"
cp "${PKG_ROOT}/composer.json" "${HERE}/pkgsrc/"
cp -r "${PKG_ROOT}/src" "${HERE}/pkgsrc/src"
cp -r "${PKG_ROOT}/bin" "${HERE}/pkgsrc/bin"
cp -r "${PKG_ROOT}/muplugins" "${HERE}/pkgsrc/muplugins"

note "Mode: ${MODE}  (Container=${CONTAINER}  Port=${PORT})"

# ── 2. Build the image. ──────────────────────────────────────────────────────
note "Building ${IMAGE} (WP + WooCommerce + install; first run is slow)"
# MSYS_NO_PATHCONV=1: on Windows Git-Bash, MSYS mangles absolute paths passed
# to non-MSYS binaries (podman.exe) — the build-context arg gets prefixed with
# a bogus C:\ segment. Disable the conversion for this one invocation and pass
# a relative path to sidestep the whole problem.
if ! ( cd "${HERE}" && MSYS_NO_PATHCONV=1 ${PODMAN} build -f Dockerfile.woocommerce -t "${IMAGE}" . ); then
    echo "Image build failed."; exit 3
fi

# ── 3. Start the container. ──────────────────────────────────────────────────
note "Starting container on ${BASE}"
${PODMAN} rm -f "${CONTAINER}" >/dev/null 2>&1 || true
# Mount the probe MU-plugin at runtime — this lets us swap probes without
# rebuilding the whole WP+WC layer while iterating.
override_cmd=""
if [[ "$MODE" == "unfixed" ]]; then
    override_cmd="cp /muworker/worker-unfixed.php /var/www/html/worker.php && "
fi
MSYS_NO_PATHCONV=1 ${PODMAN} run -d --name "${CONTAINER}" \
    -v "${HERE}/pkgsrc-muplugins:/mu:z" \
    -v "${HERE}/worker-unfixed.php:/muworker/worker-unfixed.php:z" \
    -p "${PORT}:8080" \
    "${IMAGE}" \
    /bin/sh -c "cp /mu/ephpm-wc-probe.php /var/www/html/wp-content/mu-plugins/ && ${override_cmd}ephpm serve --config /etc/ephpm/app.toml" \
    >/dev/null

# Wait for readiness.
ready=0
for i in $(seq 1 90); do
    code="$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/?ephpm-wc-probe=1" 2>/dev/null || echo 000)"
    if [[ "$code" == "200" ]]; then ready=1; break; fi
    sleep 1
done
if [[ "$ready" != "1" ]]; then
    echo "Server did not become ready. Container logs:"
    ${PODMAN} logs "${CONTAINER}" 2>&1 | tail -80
    exit 4
fi
note "Server ready. Boot logs:"
${PODMAN} logs "${CONTAINER}" 2>&1 | tail -15

# ── Discover the WC_PRODUCT_ID we created at build time. ─────────────────────
WC_PRODUCT_ID="$(${PODMAN} exec "${CONTAINER}" \
    sh -c "cat /var/www/db/wc-install.out 2>/dev/null | sed -n 's/^WC_PRODUCT_ID=//p'")"
if [[ -z "${WC_PRODUCT_ID}" ]]; then
    echo "Could not read WC_PRODUCT_ID from the image; WC install output missing"
    ${PODMAN} logs "${CONTAINER}" 2>&1 | tail -60
    exit 5
fi
WC_PRODUCT_ID_2="$(${PODMAN} exec "${CONTAINER}" \
    sh -c "cat /var/www/db/wc-install.out 2>/dev/null | sed -n 's/^WC_PRODUCT_ID_2=//p'")"
if [[ -z "${WC_PRODUCT_ID_2}" ]]; then
    echo "Could not read WC_PRODUCT_ID_2 from the image; WC install output missing"
    ${PODMAN} logs "${CONTAINER}" 2>&1 | tail -60
    exit 6
fi
note "Using WC_PRODUCT_ID=${WC_PRODUCT_ID}, WC_PRODUCT_ID_2=${WC_PRODUCT_ID_2}"

# Helper: 1 if substring present in body, 0 otherwise. Avoids grep -c/-q, which
# MSYS Git-Bash on Windows mangles by eating the flag.
contains() { case "$1" in *"$2"*) echo 1;; *) echo 0;; esac; }

# Cross-platform temp file for a curl cookie jar. On Windows Git-Bash mktemp
# hands out MSYS paths like /tmp/tmp.XXX AND $HERE comes back as /c/Users/…,
# neither of which the native curl.exe can open — jar comes back empty. Try to
# emit a Windows-native path (cygpath) but fall back to $HERE (real POSIX).
if command -v cygpath >/dev/null 2>&1; then
    JAR_DIR="$(cygpath -w "${HERE}")"
else
    JAR_DIR="${HERE}"
fi
jar_new() {
    local jar
    jar="${JAR_DIR}\\cookies-$(date +%s)-$$-${RANDOM}.jar"
    # Empty the jar via a POSIX rewrite of the same path.
    ( : > "$(command -v cygpath >/dev/null && cygpath -u "$jar" || echo "$jar")" ) 2>/dev/null || true
    printf '%s' "$jar"
}
# Read the jar via a POSIX path (awk needs it).
jar_read_names() {
    local jar_win="$1"
    local jar_posix
    if command -v cygpath >/dev/null 2>&1; then
        jar_posix="$(cygpath -u "$jar_win")"
    else
        jar_posix="$jar_win"
    fi
    awk 'NF && !/^# / { print $6 }' "$jar_posix" | tr '\n' ' '
}

# ── TEST 1: add-to-cart per-request wp_loaded ────────────────────────────────
note "TEST 1 — add-to-cart re-fires wp_loaded against request \$_GET"
# WooCommerce 9.x's WC_Form_Handler::add_to_cart_action() sets the cart
# cookies (wp_woocommerce_session_*, woocommerce_items_in_cart,
# woocommerce_cart_hash) but does NOT emit a 302 for a GET add-to-cart on
# plain permalinks — the request just renders the storefront with the
# cookies attached. The pre-fix failure mode is: cookies=[]  (no session, no
# items-in-cart) — proof that wp_loaded's add_to_cart handler never ran
# against the request $_GET.
COOKIE_JAR_A="$(jar_new)"
add_to_cart_status="$(curl -s -o /dev/null -w '%{http_code}' \
    -c "${COOKIE_JAR_A}" \
    "${BASE}/?add-to-cart=${WC_PRODUCT_ID}")"
# Read cookie names from the jar (col 6 of the Netscape format). HttpOnly
# cookies (which includes the WC session cookie) appear on lines prefixed with
# `#HttpOnly_<domain>` — so we can't skip lines starting with `#`; we must
# skip only the header comments (which start with `# `) and preserve the
# HttpOnly-prefixed lines.
cookie_names="$(jar_read_names "${COOKIE_JAR_A}")"
has_wc_session="$(contains "$cookie_names" 'wp_woocommerce_session_')"
has_items_in_cart="$(contains "$cookie_names" 'woocommerce_items_in_cart')"
t1_msg="status=${add_to_cart_status} cookies=[${cookie_names}]"
# The fix must produce BOTH the WC session cookie AND the items-in-cart cookie.
# Those cookies are only set by WC_Form_Handler::add_to_cart_action(), which
# only runs if wp_loaded fires per-request against the current $_GET.
if [[ "$add_to_cart_status" == "200" && "$has_wc_session" == "1" && "$has_items_in_cart" == "1" ]]; then
    record "1_ADD_TO_CART" 0 "$t1_msg"
else
    record "1_ADD_TO_CART" 1 "$t1_msg — expected 200 + wp_woocommerce_session_* + woocommerce_items_in_cart cookies"
fi

# ── TEST 2: Store API sees the cart the previous request built ──────────────
note "TEST 2 — Store API /wp-json/wc/store/v1/cart reflects the cart"
cart_body="$(curl -s -b "${COOKIE_JAR_A}" "${BASE}/wp-json/wc/store/v1/cart")"
cart_status="$(curl -s -o /dev/null -b "${COOKIE_JAR_A}" -w '%{http_code}' "${BASE}/wp-json/wc/store/v1/cart")"
has_prod="$(contains "$cart_body" "\"id\":${WC_PRODUCT_ID}")"
has_items="$(contains "$cart_body" '"items_count":1')$(contains "$cart_body" '"items_count":2')$(contains "$cart_body" '"items_count":3')"
if [[ "$cart_status" == "200" && "$has_prod" == "1" && "$has_items" != "000" ]]; then
    record "2_STORE_API_CART" 0 "status=${cart_status} items_count>0 & product ${WC_PRODUCT_ID} present"
else
    record "2_STORE_API_CART" 1 "status=${cart_status} has_prod=${has_prod} has_items=${has_items} body_head=$(printf '%s' "$cart_body" | head -c 300)"
fi

# ── TEST 3: two-user isolation ───────────────────────────────────────────────
note "TEST 3 — jar B adds product 2; carts don't cross-contaminate"
COOKIE_JAR_B="$(jar_new)"
curl -s -o /dev/null -c "${COOKIE_JAR_B}" \
    "${BASE}/?add-to-cart=${WC_PRODUCT_ID_2}"
cart_a="$(curl -s -b "${COOKIE_JAR_A}" "${BASE}/wp-json/wc/store/v1/cart")"
cart_b="$(curl -s -b "${COOKIE_JAR_B}" "${BASE}/wp-json/wc/store/v1/cart")"

a_has_1="$(contains "$cart_a" "\"id\":${WC_PRODUCT_ID}")"
a_has_2="$(contains "$cart_a" "\"id\":${WC_PRODUCT_ID_2}")"
b_has_1="$(contains "$cart_b" "\"id\":${WC_PRODUCT_ID}")"
b_has_2="$(contains "$cart_b" "\"id\":${WC_PRODUCT_ID_2}")"

t3_msg="cart_A(p1=${a_has_1},p2=${a_has_2}) cart_B(p1=${b_has_1},p2=${b_has_2})"
# Correct: A sees product 1 only, B sees product 2 only.
if [[ "$a_has_1" == "1" && "$a_has_2" == "0" && "$b_has_1" == "0" && "$b_has_2" == "1" ]]; then
    record "3_TWO_USER_ISOLATION" 0 "$t3_msg"
else
    record "3_TWO_USER_ISOLATION" 1 "$t3_msg — cross-contamination or missing item"
fi

# ── TEST 4: golden path still works ──────────────────────────────────────────
note "TEST 4 — golden path unregressed by the per-request re-fire"
home_code="$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/")"
rest_code="$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/wp-json/")"
if [[ "$home_code" == "200" && "$rest_code" == "200" ]]; then
    record "4_GOLDEN_PATH" 0 "home=200 rest_root=200"
else
    record "4_GOLDEN_PATH" 1 "home=${home_code} rest_root=${rest_code}"
fi

# ── TEST 5: per-request lifecycle counter observability + cost ───────────────
note "TEST 5 — did_action counters climb per request; measure cost"
probe1="$(curl -s "${BASE}/?ephpm-wc-probe=1&add-to-cart=${WC_PRODUCT_ID}")"
probe2="$(curl -s "${BASE}/?ephpm-wc-probe=1&add-to-cart=${WC_PRODUCT_ID}")"
d1_init="$(printf '%s' "$probe1" | awk -F= '$1=="did_action_init"{print $2}')"
d2_init="$(printf '%s' "$probe2" | awk -F= '$1=="did_action_init"{print $2}')"
d1_wpl="$(printf '%s' "$probe1" | awk -F= '$1=="did_action_wp_loaded"{print $2}')"
d2_wpl="$(printf '%s' "$probe2" | awk -F= '$1=="did_action_wp_loaded"{print $2}')"
observed_get_1="$(printf '%s' "$probe1" | awk -F= '$1=="wp_loaded_get_add_to_cart"{print $2}')"

# Measure per-request cost by timing 20 requests through the probe and
# reporting the median ms. This is wall-clock (curl side) — it includes the
# HTTP round-trip but the extra load is the re-fire itself since the request
# path is otherwise identical to a non-probe hit.
declare -a wall_ms
for i in $(seq 1 20); do
    t0="$(date +%s%3N)"
    curl -s -o /dev/null "${BASE}/?ephpm-wc-probe=1"
    t1="$(date +%s%3N)"
    wall_ms+=($((t1 - t0)))
done
# Median.
sorted="$(printf '%s\n' "${wall_ms[@]}" | sort -n)"
median_ms="$(printf '%s\n' "$sorted" | awk 'NR==10')"

t5_ok=1
[[ "$d2_init" -gt "$d1_init" ]] || t5_ok=0     # init counter climbed per request
[[ "$d2_wpl" -gt "$d1_wpl"   ]] || t5_ok=0     # wp_loaded counter climbed
[[ "$observed_get_1" == "${WC_PRODUCT_ID}" ]] || t5_ok=0  # wp_loaded saw request $_GET

t5_msg="init=${d1_init}→${d2_init} wp_loaded=${d1_wpl}→${d2_wpl} \$_GET_seen=${observed_get_1} probe_median=${median_ms}ms"
if [[ "$t5_ok" == "1" ]]; then
    record "5_LIFECYCLE_COST" 0 "$t5_msg"
else
    record "5_LIFECYCLE_COST" 1 "$t5_msg"
fi

# ── Summary ──────────────────────────────────────────────────────────────────
note "RESULTS"
for t in 1_ADD_TO_CART 2_STORE_API_CART 3_TWO_USER_ISOLATION 4_GOLDEN_PATH 5_LIFECYCLE_COST; do
    printf '  %-24s %s\n' "$t" "${RESULT[$t]:-SKIP}"
done
printf '\n  %d passed, %d failed\n' "$pass_count" "$fail_count"

# Cleanup cookie jars via POSIX-path form of HERE (JAR_DIR is Windows).
rm -f "${HERE}"/cookies-*.jar 2>/dev/null || true

if [[ "$fail_count" -gt 0 ]]; then
    note "Container logs (tail) for debugging failures:"
    ${PODMAN} logs "${CONTAINER}" 2>&1 | tail -60
    exit 1
fi
exit 0
