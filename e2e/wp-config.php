<?php
/**
 * WordPress configuration for the ePHPm worker e2e image.
 *
 * Uses the wp-sqlite-db drop-in (SQLite backend) so the harness is fully
 * self-contained — no MySQL sidecar. The drop-in is copied to
 * wp-content/db.php by the Dockerfile.
 */

// SQLite backend (wp-sqlite-db reads these).
if (!defined('DB_ENGINE')) {
    define('DB_ENGINE', 'sqlite');
}
define('DB_DIR', '/var/www/db/');
define('DB_FILE', 'wordpress.sqlite');

// Classic MySQL constants — unused by the SQLite drop-in but WordPress core
// still references them, so define harmless placeholders.
define('DB_NAME', 'wordpress');
define('DB_USER', 'wp');
define('DB_PASSWORD', 'wp');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wp_';

// Deterministic salts (test-only; do NOT copy to production).
define('AUTH_KEY',         'e2e-auth-key');
define('SECURE_AUTH_KEY',  'e2e-secure-auth-key');
define('LOGGED_IN_KEY',    'e2e-logged-in-key');
define('NONCE_KEY',        'e2e-nonce-key');
define('AUTH_SALT',        'e2e-auth-salt');
define('SECURE_AUTH_SALT', 'e2e-secure-auth-salt');
define('LOGGED_IN_SALT',   'e2e-logged-in-salt');
define('NONCE_SALT',       'e2e-nonce-salt');

// The site runs behind the ephpm server on :8080.
define('WP_HOME',    'http://localhost:8080');
define('WP_SITEURL', 'http://localhost:8080');

define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);
define('DISABLE_WP_CRON', true);
// Direct filesystem so install writes work in-container.
define('FS_METHOD', 'direct');

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once ABSPATH . 'wp-settings.php';
