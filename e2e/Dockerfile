# ─────────────────────────────────────────────────────────────────────────────
# E2E image for ephpm/wordpress-worker.
#
# Stage 1 (composer:2): download WordPress core, install the wp-sqlite-db
#   SQLite drop-in, install THIS package (ephpm/wordpress-worker) via composer
#   using a path repo for the package + its VCS repo for the ephpm/worker base,
#   drop in wp-config.php / worker.php / the e2e mu-plugin, and run the
#   WordPress install so there is real content (a post + admin user).
#
# Stage 2 (localhost/ephpm:worker-main): the ephpm runtime with embedded PHP in
#   worker mode. Copies the WordPress tree + vendor + worker.php + app.toml.
# ─────────────────────────────────────────────────────────────────────────────

# ── Stage 1: build the WordPress tree ────────────────────────────────────────
FROM composer:2 AS wpbuild

# curl/tar to fetch WordPress core; php-sqlite for the install-time WP-CLI-less
# bootstrap we run below.
RUN apk add --no-cache curl tar bash

ARG WP_VERSION=6.7.1

# 1. WordPress core.
WORKDIR /var/www
RUN curl -fsSL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" -o wp.tgz \
    && tar -xzf wp.tgz \
    && mv wordpress html \
    && rm wp.tgz \
    && mkdir -p /var/www/db /var/www/html/wp-content/mu-plugins

# 2. wp-sqlite-db SQLite drop-in (self-contained; no MySQL). Installed as
#    wp-content/db.php so WordPress uses SQLite transparently.
RUN curl -fsSL "https://raw.githubusercontent.com/aaemnnosttv/wp-sqlite-db/master/src/db.php" \
        -o /var/www/html/wp-content/db.php

# 3. Install ephpm/wordpress-worker via composer. The package source is copied
#    into /pkg as a path repo; ephpm/worker resolves from its VCS repo.
COPY pkgsrc/ /pkg/
WORKDIR /app
RUN cat > composer.json <<'JSON'
{
    "name": "ephpm/wordpress-worker-e2e",
    "require": { "ephpm/wordpress-worker": "*" },
    "minimum-stability": "dev",
    "prefer-stable": true,
    "repositories": [
        { "type": "path", "url": "/pkg", "options": { "symlink": false } },
        { "type": "vcs", "url": "https://github.com/ephpm/php-worker" }
    ]
}
JSON
RUN composer install --no-interaction --no-dev

# 4. Config + entrypoints.
COPY wp-config.php /var/www/html/wp-config.php
COPY worker.php   /var/www/html/worker.php
COPY muplugin/ephpm-e2e.php /var/www/html/wp-content/mu-plugins/ephpm-e2e.php

# 5. Run the WordPress install headless so the site has an admin user + a post.
#    We drive wp_install() directly with the embedded composer php (SQLite).
COPY install.php /app/install.php
RUN EPHPM_WP_PATH=/var/www/html php /app/install.php

# Ownership: ephpm runtime runs as root in the alpine image; leave as-is.

# ── Stage 2: ephpm runtime in worker mode ────────────────────────────────────
FROM localhost/ephpm:worker-main AS runtime

# WordPress tree (incl. wp-config, worker.php, mu-plugin, installed SQLite DB).
COPY --from=wpbuild /var/www /var/www
# The composer vendor tree for ephpm/wordpress-worker.
COPY --from=wpbuild /app/vendor /app/vendor

# ephpm worker-mode config.
COPY app.toml /etc/ephpm/app.toml

ENV EPHPM_WP_PATH=/var/www/html

EXPOSE 8080

# The base image entrypoint is `tini --`; override the command to serve our cfg.
CMD ["ephpm", "serve", "--config", "/etc/ephpm/app.toml"]
