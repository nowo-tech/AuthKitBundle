/*
 * AuthKit MFA code autosubmit (1.51, otp_input.mfa_code_autosubmit; sign-in challenge and setup only, never the
 * account page's disable / regenerate forms). Progressive enhancement, CSP-safe (no inline code, no eval).
 *
 * 1.52: showing the boxes is nowo-tech/otp-input-bundle's job (^1.9): its widget renders the named field as a visible
 * text input and the boxes inside a `hidden` wrapper; otp-input.js switches the input to type="hidden" and reveals the
 * boxes. This script no longer touches visibility.
 *
 * otp-input.js fires a bubbling `change` on the named code input after every box edit or paste; once an input marked
 * data-nowo-auth-kit-otp-autosubmit="1" holds a full code (data-nowo-otp-input-length-value digits), the form is
 * submitted once (requestSubmit, so the browser's own validation and submit listeners still run). The server checks
 * the code exactly as it does for a manual submit.
 * Audit QV-L2: no autosubmit while the recovery code field holds text (the recovery code would be checked instead);
 * after any submit of such a form (autosubmit, Enter or the button) a second submit is blocked until the page is
 * shown again (back/forward cache), so one code is never posted twice.
 */
(function () {
    'use strict';

    var AUTOSUBMIT = '[data-nowo-auth-kit-otp-autosubmit="1"]';
    var CODE = '[data-controller~="nowo-otp-input"]';
    var SUBMITTED = 'data-nowo-auth-kit-otp-submitted';

    function recoveryTyped(form) {
        var recovery = form.querySelector('input[name$="[recovery_code]"]');

        return recovery !== null && recovery.value.trim() !== '';
    }

    window.addEventListener('pageshow', function () {
        document.querySelectorAll('form[' + SUBMITTED + ']').forEach(function (form) {
            form.removeAttribute(SUBMITTED);
        });
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || form.querySelector(AUTOSUBMIT) === null) {
            return;
        }
        if (form.getAttribute(SUBMITTED) === '1') {
            event.preventDefault();
            event.stopImmediatePropagation();

            return;
        }
        form.setAttribute(SUBMITTED, '1');
    }, true);

    document.addEventListener('change', function (event) {
        var input = event.target;
        if (!(input instanceof HTMLInputElement) || !input.matches(CODE) || !input.matches(AUTOSUBMIT) || !input.form) {
            return;
        }
        var form = input.form;
        var length = parseInt(input.getAttribute('data-nowo-otp-input-length-value') || '0', 10);
        if (length < 1 || input.value.length !== length || !/^[0-9]+$/.test(input.value)) {
            return;
        }
        if (form.getAttribute(SUBMITTED) === '1' || recoveryTyped(form)) {
            return;
        }
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.setAttribute(SUBMITTED, '1');
            form.submit();
        }
    });
}());
