<?php

declare(strict_types=1);

/**
 * Worker entrypoint for the e2e image. Lives under document_root
 * (/var/www/html) so ephpm's worker_script validation accepts it.
 *
 * ephpm launches this per persistent worker (`[php] worker_script`). It loads
 * the Composer autoloader for the ephpm/wordpress-worker package, then boots
 * WordPress once and runs the request loop.
 */

require '/app/vendor/autoload.php';

$absPath = getenv('EPHPM_WP_PATH') ?: '/var/www/html';

exit((new Ephpm\WordPress\Worker(rtrim($absPath, '/') . '/'))->run());
