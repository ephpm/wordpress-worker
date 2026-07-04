<?php

declare(strict_types=1);

/**
 * Worker entrypoint for the e2e image. Lives under document_root
 * (/var/www/html) so ephpm's worker_script validation accepts it.
 *
 * WordPress is booted ONCE at GLOBAL scope (a top-level require, not inside a
 * function), and the request loop also runs at global scope — wp() + the
 * template loader assume global scope. See Ephpm\WordPress\Worker.
 */

use Ephpm\WordPress\RedirectSignal;
use Ephpm\WordPress\RestServed;
use Ephpm\WordPress\Worker;

require '/app/vendor/autoload.php';

$absPath = getenv('EPHPM_WP_PATH') ?: '/var/www/html';
$absPath = rtrim($absPath, '/') . '/';

Worker::defineBootConstants($absPath);
require $absPath . 'wp-load.php';   // global scope — required.

// NB: deliberately NOT named $wp — that global is WordPress's own WP router
// object; reusing the name would clobber it (and gets clobbered by the
// per-request reset). Use a distinct name for the adapter instance.
$ephpmWorker = new Worker($absPath);
$ephpmWorker->installHooks();

// The e2e site's WP_HOME is fixed but requests arrive on a mapped host/port;
// redirect_canonical would 302 every front-end request. Disable it so the
// golden path renders directly (the redirect-capture hook still protects any
// other wp_redirect() call from crashing the worker).
if (function_exists('remove_action')) {
    remove_action('template_redirect', 'redirect_canonical');
}

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
    }
}

exit(0);
