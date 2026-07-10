<?php

declare(strict_types=1);

/**
 * DEVELOPMENT-ONLY: the UNFIXED (pre-lifecycle-refire) worker.php, used to
 * reproduce the boot-once-actions bug against the fixed image. Kept in tree
 * so the PR can prove the failure mode is exactly what the reporter described.
 *
 * The only difference from worker.php is that this file does NOT call
 * Worker::resetBootActionCounters() at boot or Worker::runRequestLifecycle()
 * per iteration.
 */

use Ephpm\WordPress\RedirectSignal;
use Ephpm\WordPress\RestServed;
use Ephpm\WordPress\Worker;

require '/app/vendor/autoload.php';

$absPath = getenv('EPHPM_WP_PATH') ?: '/var/www/html';
$absPath = rtrim($absPath, '/') . '/';

Worker::defineBootConstants($absPath);
require $absPath . 'wp-load.php';

$ephpmWorker = new Worker($absPath);
$ephpmWorker->installHooks();

if (function_exists('remove_action')) {
    remove_action('template_redirect', 'redirect_canonical');
}

// NOTE: no Worker::resetBootActionCounters() and no Worker::runRequestLifecycle()
// below — this is the pre-fix behaviour used to reproduce the bug.

while (($env = \Ephpm\Worker\take_request()) !== null) {
    ob_start();
    try {
        $target = $ephpmWorker->beforeRequest($env);
        if ($target !== null) {
            require $target;
        } else {
            wp();
            require ABSPATH . WPINC . '/template-loader.php';
        }
        [$st, $hd, $body] = Worker::finishResponse((string) ob_get_clean());
        \Ephpm\Worker\send_response($st, $hd, $body);
    } catch (RedirectSignal $r) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        [$st, $hd, $body] = Worker::redirectResponse($r);
        \Ephpm\Worker\send_response($st, $hd, $body);
    } catch (RestServed $rs) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        [$st, $hd, $body] = Worker::restResponse($rs);
        \Ephpm\Worker\send_response($st, $hd, $body);
    } catch (\Throwable $e) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        [$st, $hd, $body] = Worker::errorResponseTriple($e);
        \Ephpm\Worker\send_response($st, $hd, $body);
    } finally {
        Worker::cleanupSpooledFiles();
    }
}

exit(0);
