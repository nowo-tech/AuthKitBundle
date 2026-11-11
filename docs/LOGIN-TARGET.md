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
- [Worker mode](#worker-mode)

## The problem

When a session expires, the firewall answers the next request to a protected URL with a redirect to the login page and
saves that URL as `_security.<firewall>.target_path`. If that request was a background poll (`/api/notifications/count`),
the user signs in and lands on raw JSON. A crafted `_target_path` can also point at another origin or a non-page URL.

## Post-login target guard

`LoginTargetGuard` listens to `LoginSuccessEvent` (priority -40, after the firewall's listeners, before AuthKit MFA
and remember-me) for every guarded firewall, so it covers form login, login links and AuthKit's magic link, social,
QR and registration sign-ins. When the answer is a redirect whose `Location` is refused by `LoginTargetPolicy`, only the
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

```js
const response = await fetch('/api/notifications/count', { headers: { Accept: 'application/json' } });
if (response.status === 401 && response.headers.get('X-Auth-Kit') === 'session-expired') {
    showSignInAgainPrompt(); // e.g. a dialog with a link to the login page
}
```

## How a request is classified

`NavigationKind` uses Fetch metadata when the browser sends it: `Sec-Fetch-Mode: navigate` is a page, anything else
(`cors`, `same-origin`, `no-cors`) is a background call, and only `Sec-Fetch-Dest: document` (or none) may be saved as
the target. Without Fetch metadata (old browsers, scripts): `X-Requested-With: XMLHttpRequest`, or an `Accept` that
names JSON and no HTML, is a background call; a request may become the target only when it asks for HTML (or sends no
`Accept`). The guard still checks the target at sign-in time, so a legacy client that saved a machine URL lands on the
fallback.

## Worker mode

Every service is `readonly` and stateless; the only per-request value (the target saved before a background call)
lives in a request attribute. Routes are matched with a GET copy of the router context; the shared context is never
changed. Tests run 120 sign-ins alternating page and machine targets in one kept-alive kernel
(`tests/Integration/LoginTarget/SessionExpiredKernelTest.php`) and 3,000 calls of the guard and of the 401 responder
with different users, asserting the same answers and flat memory.
