/*
 * AuthKit MFA code autosubmit (1.51, otp_input.mfa_code_autosubmit). Progressive enhancement, CSP-safe (no inline
 * code, no eval): nowo-tech/otp-input-bundle fires a bubbling `change` on the hidden code input after every box edit
 * or paste; once every box of a [data-nowo-auth-kit-otp-autosubmit="1"] group holds a digit, the form is submitted
 * once (requestSubmit, so the browser's own validation and submit listeners still run). The server checks the code
 * exactly as it does for a manual submit. Editing a box re-arms it; a page restored from the back/forward cache too.
 */
(function () {
    'use strict';

    var GROUP = '[data-nowo-auth-kit-otp-autosubmit="1"]';

    window.addEventListener('pageshow', function () {
        document.querySelectorAll('form[data-nowo-auth-kit-otp-submitted]').forEach(function (form) {
            form.removeAttribute('data-nowo-auth-kit-otp-submitted');
        });
    });

    document.addEventListener('change', function (event) {
        var input = event.target;
        if (!(input instanceof HTMLInputElement) || input.type !== 'hidden' || !input.form) {
            return;
        }
        var group = input.closest(GROUP);
        if (!group) {
            return;
        }
        var form = input.form;
        var length = parseInt(group.getAttribute('data-nowo-auth-kit-otp-length') || '0', 10);
        if (length < 1 || input.value.length !== length || !/^[0-9]+$/.test(input.value)) {
            // An edited (incomplete) code may be submitted again once it is complete.
            form.removeAttribute('data-nowo-auth-kit-otp-submitted');

            return;
        }
        if (form.getAttribute('data-nowo-auth-kit-otp-submitted') === '1') {
            return;
        }
        form.setAttribute('data-nowo-auth-kit-otp-submitted', '1');
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    });
}());
