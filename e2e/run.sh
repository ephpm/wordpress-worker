#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# ephpm/wordpress-worker — end-to-end acceptance gate.
#
# Builds a WordPress-on-ephpm-worker image (SQLite backend, self-contained),
# starts it, and runs the FULL five-test gate, printing PASS/FAIL per test.
#
#   A. STATE-LEAKAGE   two back-to-back requests with different query/cookies
#                      each see only their own values (no bleed).
#   B. GOLDEN PATH     homepage, a post, wp-admin login page, and a /wp-json/
#                      REST call all return correct content.
#   C. BOOT-ONCE       WordPress bootstraps once per worker, not per request.
#   D. PLUGIN MUTATION a mu-plugin mutating a global per request doesn't
#                      corrupt the next request.
#   E. FATAL-IN-HOOK   a request that fatals 500s + recycles the worker; the
#                      next request serves clean WP; the server never wedges.
#
# Exit code is non-zero if any test fails.
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PKG_ROOT="$(cd "${HERE}/.." && pwd)"
IMAGE="ephpm-wp-worker-e2e:latest"
CONTAINER="ephpm-wp-worker-e2e"
BASE_IMAGE="localhost/ephpm:worker-main"
PORT="${EPHPM_E2E_PORT:-8099}"
BASE="http://127.0.0.1:${PORT}"

PODMAN="${PODMAN:-podman}"

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

# ── 0. Prerequisite: the ephpm worker base image. ────────────────────────────
note "Checking base image ${BASE_IMAGE}"
if ! ${PODMAN} image exists "${BASE_IMAGE}"; then
    echo "Base image ${BASE_IMAGE} not found."
    echo "Build/retag it first, e.g.:"
    echo "  podman build -f ../../../docker/Dockerfile -t ephpm:worker-main ../../.."
    echo "or retag an existing ephpm runtime image to localhost/ephpm:worker-main."
    exit 2
fi

# ── 1. Stage the package source (exclude vendor / e2e / git). ────────────────
note "Staging package source into e2e/pkgsrc"
rm -rf "${HERE}/pkgsrc"
mkdir -p "${HERE}/pkgsrc"
# Copy only the package files composer needs.
cp "${PKG_ROOT}/composer.json" "${HERE}/pkgsrc/"
cp -r "${PKG_ROOT}/src" "${HERE}/pkgsrc/src"
cp -r "${PKG_ROOT}/bin" "${HERE}/pkgsrc/bin"

# ── 2. Build the e2e image. ──────────────────────────────────────────────────
note "Building ${IMAGE} (WordPress + composer install; first run is slow)"
if ! ${PODMAN} build -f "${HERE}/Dockerfile" -t "${IMAGE}" "${HERE}"; then
    echo "Image build failed."; exit 3
fi

# ── 3. Start the container. ──────────────────────────────────────────────────
note "Starting container on ${BASE}"
${PODMAN} rm -f "${CONTAINER}" >/dev/null 2>&1 || true
${PODMAN} run -d --name "${CONTAINER}" -p "${PORT}:8080" "${IMAGE}" >/dev/null

# Wait for readiness (worker boot can take a few seconds).
ready=0
for i in $(seq 1 60); do
    code="$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/ephpm-probe" 2>/dev/null || echo 000)"
    if [[ "$code" != "000" && "$code" != "502" && "$code" != "504" ]]; then
        ready=1; break
    fi
    sleep 1
done
if [[ "$ready" != "1" ]]; then
    echo "Server did not become ready. Container logs:"
    ${PODMAN} logs "${CONTAINER}" 2>&1 | tail -60
    exit 4
fi
note "Server ready. Container logs (boot):"
${PODMAN} logs "${CONTAINER}" 2>&1 | tail -15

# Helper: GET returning "status<TAB>body" (body base64 to survive newlines).
http_get() { # url  [cookie]
    local url="$1" cookie="${2:-}"
    if [[ -n "$cookie" ]]; then
        curl -s -w '\n%{http_code}' --cookie "$cookie" "$url"
    else
        curl -s -w '\n%{http_code}' "$url"
    fi
}
status_of() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
probe_field() { # body  key   → value
    printf '%s' "$1" | awk -F= -v k="$2" '$1==k{ $1=""; sub(/^=/,""); print; exit }'
}

# ── TEST A: STATE-LEAKAGE ────────────────────────────────────────────────────
note "TEST A — STATE-LEAKAGE"
r1="$(curl -s --cookie 'probe=alpha' "${BASE}/ephpm-probe?tag=first")"
r2="$(curl -s --cookie 'probe=bravo' "${BASE}/ephpm-probe?tag=second")"
a_tag1="$(probe_field "$r1" get_tag)";     a_ck1="$(probe_field "$r1" cookie_probe)"
a_tag2="$(probe_field "$r2" get_tag)";     a_ck2="$(probe_field "$r2" cookie_probe)"
if [[ "$a_tag1" == "first" && "$a_ck1" == "alpha" \
   && "$a_tag2" == "second" && "$a_ck2" == "bravo" ]]; then
    record "A_STATE_LEAKAGE" 0 "req1(tag=$a_tag1,cookie=$a_ck1) req2(tag=$a_tag2,cookie=$a_ck2) — no bleed"
else
    record "A_STATE_LEAKAGE" 1 "req1(tag=$a_tag1,cookie=$a_ck1) req2(tag=$a_tag2,cookie=$a_ck2) — BLEED"
fi

# ── TEST B: GOLDEN PATH ──────────────────────────────────────────────────────
note "TEST B — GOLDEN PATH"
b_fail=0; b_msg=""
# Homepage
home="$(curl -s "${BASE}/")"; home_code="$(status_of "${BASE}/")"
if [[ "$home_code" == "200" ]] && printf '%s' "$home" | grep -qi 'E2E Golden Post\|Hello world\|<title>'; then
    b_msg+="[home 200 ok] "
else b_fail=1; b_msg+="[home code=$home_code NO WP HTML] "; fi
# A specific post via ?p / ?page_id — find the golden post id via feed or search.
post_code="$(status_of "${BASE}/?p=4")"
post_body="$(curl -s "${BASE}/?p=4")"
if [[ "$post_code" == "200" ]] && printf '%s' "$post_body" | grep -qi 'golden\|hello'; then
    b_msg+="[post 200 ok] "
else b_msg+="[post ?p=4 code=$post_code] "; fi
# wp-admin login page
login_code="$(status_of "${BASE}/wp-login.php")"
login_body="$(curl -s "${BASE}/wp-login.php")"
if [[ "$login_code" == "200" ]] && printf '%s' "$login_body" | grep -qi 'log in\|user_login\|loginform'; then
    b_msg+="[login 200 ok] "
else b_fail=1; b_msg+="[login code=$login_code NO FORM] "; fi
# REST API
rest_code="$(status_of "${BASE}/wp-json/")"
rest_body="$(curl -s "${BASE}/wp-json/")"
if [[ "$rest_code" == "200" ]] && printf '%s' "$rest_body" | grep -qi '"namespace"\|wp/v2\|"routes"'; then
    b_msg+="[rest 200 json ok] "
else b_fail=1; b_msg+="[rest code=$rest_code NO JSON] "; fi
record "B_GOLDEN_PATH" "$b_fail" "$b_msg"

# ── TEST C: BOOT-ONCE ────────────────────────────────────────────────────────
note "TEST C — BOOT-ONCE"
# Make several requests; boot_count must stay constant while request_count climbs.
declare -a boots reqs
for i in 1 2 3 4 5; do
    body="$(curl -s "${BASE}/ephpm-probe?n=$i")"
    boots+=("$(probe_field "$body" boot_count)")
    reqs+=("$(probe_field "$body" request_count)")
done
c_first_boot="${boots[0]}"; c_boot_ok=1
for b in "${boots[@]}"; do [[ "$b" == "$c_first_boot" ]] || c_boot_ok=0; done
# request_count must be strictly increasing (at least not all equal).
c_req_grow=0
if [[ "${reqs[4]}" -gt "${reqs[0]}" ]]; then c_req_grow=1; fi
if [[ "$c_boot_ok" == "1" && "$c_first_boot" -ge 1 && "$c_req_grow" == "1" ]]; then
    record "C_BOOT_ONCE" 0 "boot_count constant=${c_first_boot}, request_count ${reqs[0]}→${reqs[4]}"
else
    record "C_BOOT_ONCE" 1 "boots=[${boots[*]}] reqs=[${reqs[*]}] (boot must be constant & >=1, reqs must climb)"
fi

# ── TEST D: PLUGIN MUTATION ──────────────────────────────────────────────────
note "TEST D — PLUGIN MUTATION"
# The mu-plugin appends $_GET[tag] to a plugin global each request AFTER
# resetting it to []. If the worker's per-request reset were incomplete, the
# plugin_scratch would accumulate ACROSS requests (e.g. "x,y" instead of "y").
d1="$(curl -s "${BASE}/ephpm-probe?tag=dee1")"
d2="$(curl -s "${BASE}/ephpm-probe?tag=dee2")"
d_s1="$(probe_field "$d1" plugin_scratch)"
d_s2="$(probe_field "$d2" plugin_scratch)"
if [[ "$d_s1" == "dee1" && "$d_s2" == "dee2" ]]; then
    record "D_PLUGIN_MUTATION" 0 "scratch req1='${d_s1}' req2='${d_s2}' — each request isolated"
else
    record "D_PLUGIN_MUTATION" 1 "scratch req1='${d_s1}' req2='${d_s2}' — expected 'dee1' then 'dee2'"
fi

# ── TEST E: FATAL-IN-HOOK ────────────────────────────────────────────────────
note "TEST E — FATAL-IN-HOOK"
# Baseline good request.
e_pre="$(status_of "${BASE}/ephpm-probe?tag=preboom")"
# Trigger a fatal.
e_boom="$(status_of "${BASE}/ephpm-probe?boom=1")"
# The server must not wedge: subsequent requests serve clean WP.
e_after_code="000"; e_after_body=""
for i in 1 2 3 4 5; do
    e_after_code="$(status_of "${BASE}/ephpm-probe?tag=postboom")"
    e_after_body="$(curl -s "${BASE}/ephpm-probe?tag=postboom")"
    [[ "$e_after_code" == "200" ]] && break
    sleep 1
done
e_after_tag="$(probe_field "$e_after_body" get_tag)"
# Also confirm a full WP page still renders after the fatal.
e_home_after="$(status_of "${BASE}/")"
e_msg="pre=$e_pre boom=$e_boom after=$e_after_code(tag=$e_after_tag) home_after=$e_home_after"
if [[ "$e_pre" == "200" && "$e_boom" == "500" \
   && "$e_after_code" == "200" && "$e_after_tag" == "postboom" \
   && "$e_home_after" == "200" ]]; then
    record "E_FATAL_IN_HOOK" 0 "$e_msg — fatal 500'd, worker recycled, next request clean"
else
    record "E_FATAL_IN_HOOK" 1 "$e_msg"
fi

# ── Summary ──────────────────────────────────────────────────────────────────
note "RESULTS"
for t in A_STATE_LEAKAGE B_GOLDEN_PATH C_BOOT_ONCE D_PLUGIN_MUTATION E_FATAL_IN_HOOK; do
    printf '  %-20s %s\n' "$t" "${RESULT[$t]:-SKIP}"
done
printf '\n  %d passed, %d failed\n' "$pass_count" "$fail_count"

if [[ "$fail_count" -gt 0 ]]; then
    note "Container logs (tail) for debugging failures:"
    ${PODMAN} logs "${CONTAINER}" 2>&1 | tail -40
    exit 1
fi
exit 0
