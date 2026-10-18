/*
 * AuthKit QR sign-in poller (desktop challenge page). Progressive enhancement, CSP-safe (no inline code, no eval):
 * binds every [data-nowo-auth-kit-qr] root, polls data-status-url every data-poll-interval ms, keeps the countdown
 * ([data-nowo-auth-kit-qr-region="countdown"]) and the status text ([data-nowo-auth-kit-qr-region="status"], texts
 * from data-label-<state>) up to date, sets data-nowo-auth-kit-qr-state (pending | approved | denied | expired |
 * error) on the root, dispatches a bubbling `nowo-auth-kit:qr-state` event ({detail: {state, status}}) and, once
 * approved, navigates to data-complete-url. Hosts with their own Stimulus controller set
 * nowo_auth_kit.profiles.<p>.web_ui.qr_login.stimulus_controller and this script is not loaded.
 */
(function () {
    'use strict';

    var TERMINAL = { denied: 'denied', expired: 'expired', consumed: 'expired', not_found: 'expired' };

    function pad(value) {
        return value < 10 ? '0' + value : String(value);
    }

    function format(seconds) {
        var s = Math.max(0, Math.floor(seconds));

        return Math.floor(s / 60) + ':' + pad(s % 60);
    }

    function bind(root) {
        if (root.getAttribute('data-nowo-auth-kit-qr-bound') === '1') {
            return;
        }
        root.setAttribute('data-nowo-auth-kit-qr-bound', '1');

        var scope = root.closest('form') || root;
        var part = function (name) {
            return scope.querySelector('[data-nowo-auth-kit-qr-region="' + name + '"]');
        };
        var statusEl = part('status');
        var countdownEl = part('countdown');
        var statusUrl = root.getAttribute('data-status-url');
        var completeUrl = root.getAttribute('data-complete-url');
        var interval = Math.max(500, parseInt(root.getAttribute('data-poll-interval') || '1500', 10) || 1500);
        var expiresAt = (parseInt(root.getAttribute('data-expires-at') || '0', 10) || 0) * 1000;
        var state = 'pending';
        var pollTimer = null;
        var tickTimer = null;

        function setState(next, status) {
            state = next;
            root.setAttribute('data-nowo-auth-kit-qr-state', next);
            var label = root.getAttribute('data-label-' + next);
            if (statusEl && label) {
                statusEl.textContent = label;
            }
            root.dispatchEvent(new CustomEvent('nowo-auth-kit:qr-state', { bubbles: true, detail: { state: next, status: status || next } }));
        }

        function stop() {
            window.clearTimeout(pollTimer);
            window.clearInterval(tickTimer);
        }

        function tick() {
            var left = (expiresAt - Date.now()) / 1000;
            if (countdownEl) {
                countdownEl.textContent = format(left);
            }
            if (left <= 0 && (state === 'pending' || state === 'error')) {
                stop();
                setState('expired');
            }
        }

        function schedule(delay) {
            pollTimer = window.setTimeout(poll, delay);
        }

        function poll() {
            if (!statusUrl) {
                return;
            }
            window.fetch(statusUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
                .then(function (response) {
                    return response.json().catch(function () {
                        return { status: response.ok ? 'error' : 'not_found' };
                    });
                })
                .then(function (data) {
                    var status = data && typeof data.status === 'string' ? data.status : 'error';
                    if (status === 'approved') {
                        stop();
                        setState('approved', status);
                        if (completeUrl) {
                            window.location.assign(completeUrl);
                        }

                        return;
                    }
                    if (TERMINAL[status]) {
                        stop();
                        setState(TERMINAL[status], status);

                        return;
                    }
                    if (status === 'pending') {
                        if (typeof data.expires_in === 'number') {
                            expiresAt = Date.now() + data.expires_in * 1000;
                        }
                        if (state !== 'pending') {
                            setState('pending', status);
                        }
                        schedule(interval);

                        return;
                    }
                    setState('error', status);
                    schedule(interval * 2);
                })
                .catch(function () {
                    setState('error');
                    schedule(interval * 2);
                });
        }

        tick();
        tickTimer = window.setInterval(tick, 1000);
        schedule(interval);
    }

    function boot() {
        document.querySelectorAll('[data-nowo-auth-kit-qr]').forEach(bind);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
    document.addEventListener('turbo:load', boot);
})();
