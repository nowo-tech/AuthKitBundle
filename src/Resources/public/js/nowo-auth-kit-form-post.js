/*
 * AuthKit 2.3.0 — relay of a response_mode=form_post social callback (Sign in with Apple).
 * The page holds one form marked data-nowo-auth-kit-form-post="1" with the provider's fields; it is submitted once, at
 * once, from the application's own origin so the session cookie travels with it. Progressive enhancement, CSP-safe
 * (no inline code, no eval): without scripts the visitor presses the Continue button of the same form.
 */
(function () {
    'use strict';

    function relay() {
        var form = document.querySelector('form[data-nowo-auth-kit-form-post="1"]');
        if (form === null || form.hasAttribute('data-nowo-auth-kit-form-post-sent')) {
            return;
        }
        form.setAttribute('data-nowo-auth-kit-form-post-sent', '1');
        form.submit();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', relay);
    } else {
        relay();
    }
})();
