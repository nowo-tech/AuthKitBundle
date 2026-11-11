# Post-login target guard and expired-session 401

Since 1.44. Two opt-in features for applications with background calls (polling, chat, notification bells,
`EventSource`, `fetch`):

- **`login_target`**: after a sign-in, a saved target that is not a same-origin HTML page reachable with GET (an API,
  JSON, feed, export, asset, Mercure, health, ingest or logout URL, another origin) is replaced by a fallback page.
- **`session_expired`**: a background call of an expired session gets a `401 application/problem+json` with a marker
  header instead of the firewall's `302` to the login page, so the front end can show "session expired, sign in again"
  instead of following a redirect into an HTML page.

## Table of contents

- [The problem](#the-problem)
- [Post-login target guard](#post-login-target-guard)
  - [What is refused](#what-is-refused)
  - [Configuration](#configuration)
  - [Fallback page](#fallback-page)
  - [Host voters](#host-voters)
  - [Background calls keep the saved page](#background-calls-keep-the-saved-page)
- [Expired-session 401](#expired-session-401)
  - [Front-end handling](#front-end-handling)
- [How a request is classified](#how-a-request-is-classified)
- [Trusted hosts and proxies](#trusted-hosts-and-proxies)
- [Performance](#performance)
- [Worker mode](#worker-mode)

## The problem

When a session expires, the firewall answers the next request to a protected URL with a redirect to the login page and
saves that URL as `_security.<firewall>.target_path`. If that request was a background poll (`/api/notifications/count`),
the user signs in and lands on raw JSON. A crafted `_target_path` can also point at another origin or a non-page URL.

## Post-login target guard

`LoginTargetGuard` listens to `LoginSuccessEvent` (priority -40, after the firewall's listeners, before AuthKit MFA
and remember-me) for every guarded firewall, so it covers form login, login links and AuthKit's magic link, social,
QR, registration and invitation sign-ins. Form login, login links, magic links, registration and invitations go to
the saved target when it is allowed; **social and QR sign-ins never use the saved target** (they always land on the
profile's `login_success_route`, as before 1.44), the guard only removes a refused saved target from the session. When the answer is a redirect whose `Location` is refused by `LoginTargetPolicy`, only the
`Location` changes (cookies and headers set by other listeners stay). A refused saved target is also removed from the
session.

### What is refused

Checks, in order:

1. **Same origin, never overridable.** The target must be an absolute path (`/…`, not `//…` nor `/\…`) or an absolute
   `http(s)` URL of the request's own scheme, host and port. User info, backslashes, whitespace and control characters
   (including raw CR/LF, tab and NUL) are refused. `javascript:`, `data:`, `https:evil`, `//evil`, `/\evil` and
   `http://example.com@evil` are all refused. Percent-encoded payloads (`/%2F%2Fevil`, `/%0d%0a…`) are opaque to the
   browser and stay paths of this origin.
2. **Host voters** (`LoginTargetVoterInterface`): the first boolean answer wins.
3. **Configured rules:** denied path prefixes (matched on whole segments), denied path patterns, denied route names and
   route-name patterns, a route option set to `false` (`#[Route(options: ['html' => false])]`), a `_format` other than
   `html`, a controller whose declared return type is a `JsonResponse`, `StreamedResponse` or `BinaryFileResponse`,
   and (with `require_route`) a path that matches no GET route.

The path rules see the path both as sent and percent-decoded once, like the router (`/%61pi/raw` is refused like
`/api/raw`), and prefixes compare case-insensitively. A deny pattern that fails at run time (PCRE backtrack or
recursion limit) counts as a denial: the rules fail closed.

The default lists deny `/.well-known`, `/health`, `/healthz`, `/metrics`, `/otlp`, `/ingest`, `/mcp`, `/build`,
`/bundles`, `/assets`, an `api` segment anywhere (`/api/…`, `/en/api/…`, `/chat/api/…`), a segment starting with `_`
(`/_wdt`, `/_profiler`, `/_fragment`), a `logout` segment or route name, and data or asset file extensions (`.json`,
`.xml`, `.csv`, `.ics`, `.js`, `.css`, `.pdf`, images, fonts…). See `Configuration::DEFAULT_DENIED_*`.

### Configuration

```yaml
nowo_auth_kit:
    login_target:
        enabled: true                     # default false (opt-in)
        firewalls: []                     # empty: the firewall of every profile
        fallback_route: null              # null: the profile's login_success_route, else "/"
        fallback_provider: null           # service id of a LoginTargetFallbackProviderInterface
        denied_path_prefixes: [...]       # setting the key replaces the default list
        denied_path_patterns: [...]       # PCRE, matched on the path without the base URL
        denied_routes: []                 # route names
        denied_route_patterns: [...]      # PCRE, matched on the route name
        route_option: html                # null: ignore route options
        require_route: true               # refuse a path that matches no GET route
        check_controller_response: true   # refuse JSON / streamed / file controllers
        preserve_on_background_requests: true
```

Patterns are validated when the container is built. Keep your own entries when overriding a list, for example:

```yaml
nowo_auth_kit:
    login_target:
        enabled: true
        denied_path_prefixes: ['/.well-known', '/health', '/metrics', '/build', '/bundles', '/assets', '/feeds']
        denied_routes: [wiki_comments_json]
```

The policy service (`Nowo\AuthKitBundle\Security\LoginTarget\LoginTargetPolicy`) is always registered, so host code
can validate its own redirect targets with `allows($target, $request, $firewall)`.

### Fallback page

The refused target is replaced by, in order: the path returned by the optional `fallback_provider` (per-user start page;
a value that is not a same-origin path is ignored), `fallback_route`, the `login_success_route` of the profile on that
firewall, then the application root.

Each fallback route must exist and need no parameters: the check runs at cache warm-up (`cache:warmup`, or the first
boot after a cache clear), so a wrong `fallback_route` fails the deployment rather than a sign-in. Routes are not known
when the container is compiled, so the check cannot run earlier. Should a route still fail to generate at run time,
the guard falls back to the application root.

```php
final class StartPageFallback implements LoginTargetFallbackProviderInterface
{
    public function fallback(string $firewall, string $userIdentifier): ?string
    {
        return '/dashboard';
    }
}
```

### Host voters

A voter sees a `LoginTarget` value object (plain strings: `path`, `query`, `routeName`, `firewall`; no HTTP or
Doctrine types, usable from a DDD host). Services implementing `LoginTargetVoterInterface` are autoconfigured (tag
`nowo_auth_kit.login_target_voter`, `priority` honoured). Return `true` to keep the target, `false` to refuse it, `null`
to abstain. Voters only see targets that already passed the same-origin checks: a voter can never allow an open
redirect.

```php
final class DocsApiPageVoter implements LoginTargetVoterInterface
{
    public function vote(LoginTarget $target): ?bool
    {
        return $target->path === '/docs/api' ? true : null; // an HTML page under an "api" segment
    }
}
```

### Background calls keep the saved page

With `preserve_on_background_requests` (default when the guard is enabled), a background call of a signed-out session
(fetch, XHR, `EventSource`, a JSON `Accept`) that the firewall saves as the target is undone on the response: the page
the user was sent to the login from stays the target. Only a top-level GET navigation that asks for HTML may become
the target.

## Expired-session 401

```yaml
nowo_auth_kit:
    session_expired:
        enabled: true                     # default false (opt-in)
        firewalls: []                     # empty: the firewall of every profile
        header_name: X-Auth-Kit
        header_value: session-expired
        problem_type: about:blank
        problem_title: Unauthorized
        code: session_expired
```

For a background call of a guarded, stateful firewall that needs authentication (no token, or a token that is not
fully authenticated hitting an access check), `SessionExpiredResponder` (`kernel.exception`, priority 2, before the
firewall's exception listener) answers:

```http
HTTP/1.1 401 Unauthorized
Content-Type: application/problem+json
Cache-Control: no-store, private
X-Auth-Kit: session-expired

{"type":"about:blank","title":"Unauthorized","status":401,"code":"session_expired"}
```

The request is not saved as the post-login target. A fully authenticated user denied access still gets the firewall's
403; page navigations still get the login redirect; stateless firewalls (token APIs) are left alone.

### Front-end handling

**Turbo Drive / Turbo Frames and htmx** load pages with `fetch` (`Sec-Fetch-Mode: cors`), so with `session_expired`
on they get the JSON 401 instead of the login redirect. Turbo then reloads the page in full (and the firewall
redirects that navigation to the login page); htmx does not swap a 4xx answer and does nothing unless the front end
handles the marker. Handle the marker once for every kind of call:

```js
const LOGIN_URL = '/login';
const isSessionExpired = (status, header) => status === 401 && header === 'session-expired';

// fetch (your own calls)
export async function apiFetch(url, init = {}) {
    const response = await fetch(url, { ...init, headers: { Accept: 'application/json', ...init.headers } });
    if (isSessionExpired(response.status, response.headers.get('X-Auth-Kit'))) {
        showSignInAgainPrompt(LOGIN_URL); // e.g. a dialog with a link to the login page
    }
    return response;
}

// Turbo Drive and Turbo Frames
document.addEventListener('turbo:before-fetch-response', (event) => {
    const response = event.detail.fetchResponse.response;
    if (isSessionExpired(response.status, response.headers.get('X-Auth-Kit'))) {
        event.preventDefault();
        window.location.assign(LOGIN_URL); // or showSignInAgainPrompt(LOGIN_URL)
    }
});

// htmx
document.body.addEventListener('htmx:beforeSwap', (event) => {
    const xhr = event.detail.xhr;
    if (isSessionExpired(xhr.status, xhr.getResponseHeader('X-Auth-Kit'))) {
        event.detail.shouldSwap = false;
        window.location.assign(LOGIN_URL);
    }
});
```

Use your `header_name` if you changed it. A login redirect after sign-in goes back to the saved page (the 401 call
itself is never saved as the target).

### Order with MFA

With MFA, a request of a session whose second step is pending is answered by MFA containment first (`kernel.exception`
priority 4: `401 {"error":"mfa_required"}` for JSON, a redirect to the challenge for pages); the expired-session
responder runs next (priority 2), then the firewall (1).

## How a request is classified

`NavigationKind` uses Fetch metadata when the browser sends it: `Sec-Fetch-Mode: navigate` is a page, anything else
(`cors`, `same-origin`, `no-cors`) is a background call, and only `Sec-Fetch-Dest: document` (or none) may be saved as
the target. Without Fetch metadata (old browsers, scripts): `X-Requested-With: XMLHttpRequest`, or an `Accept` that
names JSON and no HTML, is a background call; a request may become the target only when it asks for HTML (or sends no
`Accept`). The guard still checks the target at sign-in time, so a legacy client that saved a machine URL lands on the
fallback.

## Trusted hosts and proxies

"Same origin" is the scheme, host and port of the sign-in request (`Request::getSchemeAndHttpHost()`), as in Symfony's
own target-path checks. A browser cannot forge the `Host` header of a victim's request, but set
`framework.trusted_hosts` to your domains and configure `framework.trusted_proxies` / `trusted_headers` only for your
own reverse proxies, so that `Host` and `X-Forwarded-*` values from elsewhere are never trusted.

## Performance

The guard matches the target with a copy of the router's compiled matcher. Outside debug, the route rules (denied route
names and patterns, the route option, `_format`, controller return types) are evaluated once by a cache warmer into
`%kernel.build_dir%/nowo_auth_kit/login_target_routes.php`; a sign-in never loads the route collection. In debug
(routes change without a cache build) or when the table is missing, the policy reads the route collection instead.

## Worker mode

Every service is `readonly` and stateless; the only per-request value (the target saved before a background call)
lives in a request attribute. Routes are matched with a copy of the compiled matcher set to a GET copy of the router
context; the shared matcher and context are never changed. The warmed route table is a PHP file returning a constant
array (shared by opcache), written atomically at warm-up. Tests run 120 sign-ins alternating page and machine targets in one kept-alive kernel
(`tests/Integration/LoginTarget/SessionExpiredKernelTest.php`) and 3,000 calls of the guard and of the 401 responder
with different users, asserting the same answers and flat memory.
