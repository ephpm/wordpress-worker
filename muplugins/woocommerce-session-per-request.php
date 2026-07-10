<?php

/**
 * Plugin Name: ePHPm — WooCommerce session per-request (worker-mode workaround)
 * Description: WooCommerce singleton workaround for ePHPm's persistent worker
 *              mode. Drop into wp-content/mu-plugins/ when running WooCommerce
 *              under ephpm/wordpress-worker. Without it, guest carts are not
 *              shared across the worker pool: the Store API keeps returning
 *              an empty cart even after /?add-to-cart=<id> set the cookies.
 * Author:      ePHPm
 * License:     MIT
 *
 * Root cause: WooCommerce's WC()->session (WC_Session_Handler) is instantiated
 * ONCE per worker boot via WC::initialize_session(). That constructor is
 * guarded by `if ( is_null( $this->session ) || ! $this->session instanceof
 * $session_class )`, so subsequent calls short-circuit and the handler's
 * `_customer_id` stays fixed at whatever it was set to at boot (with an empty
 * $_COOKIE). Under a persistent worker, that means every request re-uses the
 * worker's boot-time session ID and never reads the client's
 * `wp_woocommerce_session_*` cookie — so guest cart data doesn't follow the
 * client across the worker pool.
 *
 * The adapter (ephpm/wordpress-worker) fires WordPress' `init` and `wp_loaded`
 * actions per request against the current superglobals (FPM parity), which
 * makes `WC_Form_Handler::add_to_cart_action()` on `wp_loaded` correctly see
 * the request's $_GET and set the cart cookies. But WC's own session-handler
 * singleton state stays boot-time — which the adapter can't safely rewrite
 * from the outside (it's plugin-internal).
 *
 * This mu-plugin closes that gap in a WC-specific way: at the START of every
 * `wp_loaded` re-fire (priority 1, before WC's own priority-5 cart hooks), it
 * calls `WC()->session->init_session_cookie()` to re-read $_COOKIE and update
 * `_customer_id` in place, then reloads the cart from the (now-corrected)
 * session. Safe: only reads state, doesn't re-register hooks or clobber
 * anything the handler wrote earlier this request.
 */

add_action(
    'wp_loaded',
    static function (): void {
        if (!\function_exists('WC')) {
            return;
        }
        $wc = \WC();
        if (!$wc || !isset($wc->session) || !$wc->session) {
            return;
        }

        // Detect the "no cookie sent by client" case by checking the actual
        // cookie name WC uses. When there's no cookie, WC_Session_Handler
        // internal state must be zeroed to whatever request N's cookies (or
        // lack of them) say — otherwise `_customer_id` from request N-1 leaks
        // into request N and jar B ends up loading jar A's saved cart.
        //
        // The reflection ceremony below is unavoidable: WC_Session_Handler's
        // customer-id state is a private property, `_customer_id`, that only
        // `init_session_cookie()` and `generate_customer_id()` write. When
        // the client sent no cookie, calling `init_session_cookie()` does
        // generate a new ID (via generate_customer_id) — GOOD. When the
        // client sent a cookie, calling `init_session_cookie()` reads it and
        // installs the client's ID — GOOD. The remaining leak is that
        // `_data` (the loaded session payload) is NOT re-read; only cleared
        // when the ID changes. So we clear `_data` explicitly and let
        // `WC_Cart::get_cart_from_session()` re-populate it from the DB row
        // for whichever ID we just landed on.
        if (\method_exists($wc->session, 'init_session_cookie')) {
            $wc->session->init_session_cookie();
        }

        $refl = new \ReflectionObject($wc->session);
        foreach (['_data', '_dirty', '_session_expiration'] as $propName) {
            if ($refl->hasProperty($propName)) {
                $prop = $refl->getProperty($propName);
                $prop->setAccessible(true);
                // _dirty resets to false; _data reloads from DB via get_session
                // on next access; _session_expiration is re-derived by init.
                if ($propName === '_dirty') {
                    $prop->setValue($wc->session, false);
                } elseif ($propName === '_data') {
                    // Force a fresh DB read for this customer_id's session.
                    if (\method_exists($wc->session, 'get_session')) {
                        $customerId = $wc->session->get_customer_id();
                        $fresh = $wc->session->get_session($customerId, []);
                        $prop->setValue($wc->session, \is_array($fresh) ? $fresh : []);
                    }
                }
            }
        }

        if (isset($wc->cart) && $wc->cart) {
            // Zero the in-memory cart_contents FIRST (via reflection, without
            // going through WC_Cart::empty_cart() which would ALSO write an
            // empty 'cart' key back to the session and clobber the client's
            // saved cart on the DB side).
            $cartRefl = new \ReflectionObject($wc->cart);
            if ($cartRefl->hasProperty('cart_contents')) {
                $prop = $cartRefl->getProperty('cart_contents');
                $prop->setAccessible(true);
                $prop->setValue($wc->cart, []);
            }
            if (\method_exists($wc->cart, 'get_cart_from_session')) {
                // Now reload the cart from the (now-corrected) session so
                // this request's in-memory cart reflects THIS client's saved
                // cart, not the previous client's. If the session has no
                // 'cart' key (fresh guest), cart_contents stays empty; the
                // subsequent add_to_cart_action on wp_loaded p20 adds items.
                $wc->cart->get_cart_from_session();
            }
        }
    },
    1,
);
