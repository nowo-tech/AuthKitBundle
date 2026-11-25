/*
 * AuthKit passkeys (2.4.0). Progressive enhancement, CSP-safe (no inline code, no eval): binds every
 * [data-nowo-auth-kit-webauthn="login|mfa|register"] root. It fetches the ceremony options from data-options-url
 * (JSON, same origin, credentials included), runs navigator.credentials.get() / create(), writes the JSON of the
 * answer into the form's hidden [data-nowo-auth-kit-webauthn-credential] field and submits the form (an ordinary
 * CSRF-protected POST). Login roots with data-conditional="1" also start the conditional UI, so the browser offers the
 * passkeys of this site (or "use a phone": the hybrid QR transport) from the field with
 * autocomplete="username webauthn". The root gets data-nowo-auth-kit-webauthn-state (idle | working | unsupported |
 * failed | cancelled) and a bubbling `nowo-auth-kit:webauthn-state` event ({detail: {mode, state}}); the status text
 * comes from data-label-<state>. Nothing secret is logged.
 */
(function () {
    'use strict';

    function toBase64Url(buffer) {
        var bytes = new Uint8Array(buffer);
        var text = '';
        for (var i = 0; i < bytes.length; i++) {
            text += String.fromCharCode(bytes[i]);
        }

        return btoa(text).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function fromBase64Url(value) {
        var base64 = String(value).replace(/-/g, '+').replace(/_/g, '/');
        while (base64.length % 4) {
            base64 += '=';
        }
        var text = atob(base64);
        var bytes = new Uint8Array(text.length);
        for (var i = 0; i < text.length; i++) {
            bytes[i] = text.charCodeAt(i);
        }

        return bytes.buffer;
    }

    function descriptors(list) {
        return (list || []).map(function (item) {
            var copy = Object.assign({}, item);
            copy.id = fromBase64Url(item.id);

            return copy;
        });
    }

    function requestOptions(json) {
        if (window.PublicKeyCredential && typeof PublicKeyCredential.parseRequestOptionsFromJSON === 'function') {
            return PublicKeyCredential.parseRequestOptionsFromJSON(json);
        }
        var options = Object.assign({}, json);
        options.challenge = fromBase64Url(json.challenge);
        options.allowCredentials = descriptors(json.allowCredentials);

        return options;
    }

    function creationOptions(json) {
        if (window.PublicKeyCredential && typeof PublicKeyCredential.parseCreationOptionsFromJSON === 'function') {
            return PublicKeyCredential.parseCreationOptionsFromJSON(json);
        }
        var options = Object.assign({}, json);
        options.challenge = fromBase64Url(json.challenge);
        options.user = Object.assign({}, json.user, { id: fromBase64Url(json.user.id) });
        options.excludeCredentials = descriptors(json.excludeCredentials);

        return options;
    }

    function serialize(credential) {
        if (typeof credential.toJSON === 'function') {
            return JSON.stringify(credential.toJSON());
        }
        var response = credential.response;
        var json = {
            id: credential.id,
            rawId: toBase64Url(credential.rawId),
            type: credential.type,
            authenticatorAttachment: credential.authenticatorAttachment || null,
            clientExtensionResults: credential.getClientExtensionResults ? credential.getClientExtensionResults() : {},
            response: { clientDataJSON: toBase64Url(response.clientDataJSON) }
        };
        if (response.attestationObject) {
            json.response.attestationObject = toBase64Url(response.attestationObject);
            json.response.transports = typeof response.getTransports === 'function' ? response.getTransports() : [];
        } else {
            json.response.authenticatorData = toBase64Url(response.authenticatorData);
            json.response.signature = toBase64Url(response.signature);
            json.response.userHandle = response.userHandle ? toBase64Url(response.userHandle) : null;
        }

        return JSON.stringify(json);
    }

    function bind(root) {
        if (root.getAttribute('data-nowo-auth-kit-webauthn-bound') === '1') {
            return;
        }
        root.setAttribute('data-nowo-auth-kit-webauthn-bound', '1');

        var mode = root.getAttribute('data-nowo-auth-kit-webauthn');
        var form = root.querySelector('form');
        var field = root.querySelector('[data-nowo-auth-kit-webauthn-credential]');
        var button = root.querySelector('[data-nowo-auth-kit-webauthn-start]');
        var status = root.querySelector('[data-nowo-auth-kit-webauthn-status]');
        var optionsUrl = root.getAttribute('data-options-url');
        var conditional = null;
        var busy = false;

        function state(name) {
            root.setAttribute('data-nowo-auth-kit-webauthn-state', name);
            if (status) {
                status.textContent = root.getAttribute('data-label-' + name) || '';
            }
            root.dispatchEvent(new CustomEvent('nowo-auth-kit:webauthn-state', { bubbles: true, detail: { mode: mode, state: name } }));
        }

        if (!form || !field || !optionsUrl || !window.PublicKeyCredential || !navigator.credentials) {
            state('unsupported');
            if (button) {
                button.disabled = true;
            }

            return;
        }

        function fetchOptions() {
            return fetch(optionsUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('options ' + response.status);
                    }

                    return response.json();
                });
        }

        function submit(credential) {
            field.value = serialize(credential);
            form.submit();
        }

        function fail(error) {
            busy = false;
            state(error && (error.name === 'NotAllowedError' || error.name === 'AbortError') ? 'cancelled' : 'failed');
        }

        function conditionalAvailable() {
            if (typeof PublicKeyCredential.getClientCapabilities === 'function') {
                return PublicKeyCredential.getClientCapabilities().then(function (capabilities) {
                    return capabilities.conditionalGet === true;
                });
            }
            if (typeof PublicKeyCredential.isConditionalMediationAvailable === 'function') {
                return PublicKeyCredential.isConditionalMediationAvailable();
            }

            return Promise.resolve(false);
        }

        function startConditional() {
            conditionalAvailable().then(function (available) {
                if (!available) {
                    return;
                }
                conditional = new AbortController();
                var signal = conditional.signal;
                fetchOptions().then(function (json) {
                    return navigator.credentials.get({ publicKey: requestOptions(json), mediation: 'conditional', signal: signal });
                }).then(function (credential) {
                    if (credential) {
                        state('working');
                        submit(credential);
                    }
                }).catch(function () {
                    // Aborted by the button, or nothing chosen: the explicit ceremony stays available.
                });
            });
        }

        function run() {
            if (busy) {
                return;
            }
            busy = true;
            if (conditional) {
                conditional.abort();
                conditional = null;
            }
            state('working');
            fetchOptions().then(function (json) {
                return mode === 'register'
                    ? navigator.credentials.create({ publicKey: creationOptions(json) })
                    : navigator.credentials.get({ publicKey: requestOptions(json) });
            }).then(function (credential) {
                if (!credential) {
                    throw new Error('empty');
                }
                submit(credential);
            }).catch(fail);
        }

        if (mode === 'register') {
            form.addEventListener('submit', function (event) {
                if (field.value === '') {
                    event.preventDefault();
                    run();
                }
            });
        } else if (button) {
            button.addEventListener('click', run);
        }
        state('idle');
        if (mode === 'login' && root.getAttribute('data-conditional') === '1') {
            startConditional();
        }
    }

    /*
     * Account page: tell the password manager which passkeys the server still accepts (Signal API), so a passkey
     * removed here disappears from the user's vault too. Passive: never breaks the page, warns only.
     */
    function signal(root) {
        if (!window.PublicKeyCredential || typeof PublicKeyCredential.signalAllAcceptedCredentials !== 'function') {
            return;
        }
        try {
            var data = JSON.parse(root.getAttribute('data-nowo-auth-kit-webauthn-signal') || '{}');
            var valid = /^[A-Za-z0-9_-]+$/;
            var ids = (data.allAcceptedCredentialIds || []).filter(function (id) {
                return valid.test(id) && id.length % 4 !== 1;
            });
            if (!data.rpId || !valid.test(data.userId || '')) {
                return;
            }
            PublicKeyCredential.signalAllAcceptedCredentials({ rpId: data.rpId, userId: data.userId, allAcceptedCredentialIds: ids })
                .catch(function (error) {
                    console.warn('AuthKit: passkey list sync failed', error);
                });
        } catch (error) {
            console.warn('AuthKit: passkey list sync failed', error);
        }
    }

    function init() {
        document.querySelectorAll('[data-nowo-auth-kit-webauthn]').forEach(bind);
        document.querySelectorAll('[data-nowo-auth-kit-webauthn-signal]').forEach(signal);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
