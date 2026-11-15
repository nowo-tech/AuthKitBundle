/*
 * AuthKit MFA code on OTP boxes (1.51.1): shows the boxes once otp-input.js has bound them. The page renders the code
 * field as a visible text input and the boxes with `hidden` (works with no script at all); when a
 * `.nowo-auth-kit-mfa-otp` container gets data-nowo-otp-init="1" this script removes `hidden` from the boxes and turns
 * the text input into a hidden input (it keeps the value the boxes write). Done here, not in a stylesheet, so a host
 * that does not load nowo-auth-kit.css, or whose CSS framework hides [hidden] with a layered !important (Tailwind 4),
 * always sees either the boxes or the text input.
 *
 * Autosubmit (1.51, otp_input.mfa_code_autosubmit; sign-in challenge and setup only, never the
 * account page's disable / regenerate forms). Progressive enhancement, CSP-safe (no inline code, no eval):
 * nowo-tech/otp-input-bundle fires a bubbling `change` on the code input (the field's own named input, a text input
 * hidden once the boxes are bound) after every box edit or paste; once every box of a
 * [data-nowo-auth-kit-otp-autosubmit="1"] group holds a digit, the form is submitted once (requestSubmit, so the
 * browser's own validation and submit listeners still run). The server checks the code exactly as it does for a
 * manual submit.
 * Audit QV-L2: no autosubmit while the recovery code field holds text (the recovery code would be checked instead);
 * after any submit of such a form (autosubmit, Enter or the button) a second submit is blocked until the page is
 * shown again (back/forward cache), so one code is never posted twice.
 */
(function () {
    'use strict';

    var GROUP = '[data-nowo-auth-kit-otp-autosubmit="1"]';
    var CODE = '[data-controller~="nowo-otp-input"]';
    var SUBMITTED = 'data-nowo-auth-kit-otp-submitted';

    function recoveryTyped(form) {
        var recovery = form.querySelector('input[name$="[recovery_code]"]');

        return recovery !== null && recovery.value.trim() !== '';
    }

    var WIDGET = '.nowo-auth-kit-mfa-otp';
    var HOST = '[data-nowo-otp-container]';

    function enhance(host) {
        if (host.getAttribute('data-nowo-otp-init') !== '1' || host.closest(WIDGET) === null) {
            return;
        }
        var text = host.querySelector(CODE);
        var boxes = host.querySelector('[data-nowo-otp-render-target]');
        if (!(text instanceof HTMLInputElement) || boxes === null || text.type === 'hidden') {
            return;
        }
        var focused = document.activeElement === text;
        boxes.removeAttribute('hidden');
        text.type = 'hidden';
        host.closest(WIDGET).setAttribute('data-nowo-auth-kit-otp-ready', '1');
        if (focused) {
            var first = boxes.querySelector('[data-nowo-otp-digit]');
            if (first instanceof HTMLElement) {
                first.focus();
            }
        }
    }

    function enhanceAll() {
        document.querySelectorAll(WIDGET + ' ' + HOST).forEach(enhance);
    }

    if (typeof MutationObserver !== 'undefined') {
        new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                if (mutation.target instanceof HTMLElement && mutation.target.matches(HOST)) {
                    enhance(mutation.target);
                }
            });
        }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-nowo-otp-init'], subtree: true });
    }
    enhanceAll();
    document.addEventListener('DOMContentLoaded', enhanceAll);
    window.addEventListener('load', enhanceAll);

    window.addEventListener('pageshow', function () {
        document.querySelectorAll('form[' + SUBMITTED + ']').forEach(function (form) {
            form.removeAttribute(SUBMITTED);
        });
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || form.querySelector(GROUP) === null) {
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
        if (!(input instanceof HTMLInputElement) || !input.matches(CODE) || !input.form) {
            return;
        }
        var group = input.closest(GROUP);
        if (!group) {
            return;
        }
        var form = input.form;
        var length = parseInt(group.getAttribute('data-nowo-auth-kit-otp-length') || '0', 10);
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
