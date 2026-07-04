<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for ephpm/wordpress-worker.
 *
 * Loads the Composer autoloader. WordPress and the native ePHPm worker
 * primitives are intentionally NOT loaded here — the unit suite exercises the
 * pure, testable helpers of Ephpm\WordPress\Worker with fake envelopes and
 * stubbed globals only. Anything that needs a live WordPress or the native
 * take_request()/send_response() primitives is covered by the e2e suite.
 */

require __DIR__ . '/../vendor/autoload.php';
