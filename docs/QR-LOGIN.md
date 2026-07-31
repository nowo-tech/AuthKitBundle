# QR phone login

> **Status: implemented (P0)** — Config, entity, services, controllers, Twig templates, desktop cookie binding, rate limiting, and tests shipped. Default mode is `disabled` (opt-in). P1+ features (phone_otp, QR PNG library, audit table) remain unimplemented.
>
> Aligns with AuthKit patterns (`magic_login` / `social_login`: profile gate, Doctrine tables owned by the bundle, public routes + Twig login affordance).

Passwordless sign-in where the **desktop** shows a QR challenge and the **phone** (number already linked to the user) approves it. The QR never embeds PII; only an opaque challenge id + HMAC.

## Table of contents

- [Goals / non-goals](#goals--non-goals)
- [Actors and prerequisites](#actors-and-prerequisites)
- [Configuration](#configuration)
- [User entity (app-owned phone)](#user-entity-app-owned-phone)
- [Tables](#tables)
- [Routes](#routes)
- [Sequence](#sequence)
- [Services](#services)
- [Security controls (mandatory)](#security-controls-mandatory)
  - [1. QR photo / shoulder-surf](#1-qr-photo--shoulder-surf)
  - [2. SIM swap / stolen phone](#2-sim-swap--stolen-phone)
  - [3. Privacy (QR payload)](#3-privacy-qr-payload)
  - [4. Rate limiting](#4-rate-limiting)
- [UX](#ux)
- [Login template variables](#login-template-variables)
- [Events](#events)
- [Phased delivery](#phased-delivery)
- [Open decisions](#open-decisions)

## Goals / non-goals

**Goals**

- Opt-in profile feature (`qr_login.mode`), default `disabled`.
- Desktop polls until approved / expired / denied; then Symfony `Security::login`.
- Phone proves possession via authenticated mobile session **or** one-time approve link delivered to the verified phone (SMS / WhatsApp / push — app-owned notifier).
- Bundle owns challenge persistence, desktop binding cookie, and rate limits; app owns phone field, step-up unlock, and OTP delivery.

**Non-goals (v1)**

- Native mobile SDK / push infrastructure inside the bundle.
- Account recovery / change-phone flows (document as app responsibility).
- Cross-device session sync beyond “approve this login”.
- WebAuthn / passkeys (orthogonal; can coexist later).

## Actors and prerequisites

| Actor | Role |
|-------|------|
| Desktop browser | Starts challenge, shows QR + short code, polls status, receives session |
| Phone browser / app | Opens approve URL from QR (or deep link), confirms after phone auth |
| User entity | **Must** expose a non-empty phone **and** a non-null verification date (`phone` + `phoneVerifiedAt` by default) |

Prerequisites before buttons appear:

1. `qr_login.mode: enabled`
2. Profile `user_class` resolves users that have a non-empty verified phone (gate can be “any user may try”; enumeration-safe UX still applies)
3. App registers `QrLoginNotifierInterface` if using “SMS deep link” path (optional when phone already has an AuthKit/session cookie on a companion app)

## Configuration

```yaml
nowo_auth_kit:
    profiles:
        default:
            qr_login:
                mode: enabled                 # disabled | enabled
                challenge_ttl: 90             # seconds (max recommended 180)
                poll_interval_ms: 1500        # hint for Twig/JS
                # How the phone proves the user (see Security)
                approve_requires: session_step_up  # session_step_up | session | phone_otp | either
                # Desktop must present the same browser cookie + optional IP/UA match on complete
                desktop_binding: strict       # off | soft | strict
                rate_limit:
                    create_per_ip: '5/10 minutes'
                    status_per_ip: '120/1 minute'
                    approve_per_ip: '20/10 minutes'
                    approve_per_challenge: '5/challenge'
                    otp_send_per_phone: '3/10 minutes'
                phone_field: phone
                phone_verified_field: phoneVerifiedAt
                create_user_if_missing: false
            routes:
                qr_login_start:
                    path: /login/qr
                    name: nowo_auth_kit_qr_login_start
                qr_login_status:
                    path: /login/qr/{id}/status
                    name: nowo_auth_kit_qr_login_status
                qr_login_show:
                    path: /login/qr/{id}
                    name: nowo_auth_kit_qr_login_show
                qr_login_approve:
                    path: /login/qr/{id}/approve
                    name: nowo_auth_kit_qr_login_approve
                qr_login_deny:
                    path: /login/qr/{id}/deny
                    name: nowo_auth_kit_qr_login_deny
                qr_login_complete:
                    path: /login/qr/{id}/complete
                    name: nowo_auth_kit_qr_login_complete
```

| Key | Meaning |
|-----|---------|
| `approve_requires` | **`session_step_up` (default recommended):** phone already logged in **and** local unlock via `QrLoginStepUpInterface` (PIN / biometrics / WebAuthn). `session` = logged-in phone only. `phone_otp` = SMS/WhatsApp OTP only (weaker vs SIM swap). `either` = session_step_up **or** phone_otp |
| `desktop_binding` | `strict` (default): `complete` / `status` require desktop challenge cookie **and** matching IP+UA hashes. `soft`: cookie required; IP/UA mismatch → allow + `QrLoginBindingMismatchEvent`. `off`: cookie only |
| `challenge_ttl` | Hard expiry for QR + approve secret (default **90s**, clamp 30–180) |
| `rate_limit.*` | Symfony RateLimiter policies (bundle ships defaults; apps may override) |
| `phone_field` / `phone_verified_field` | App-owned; PropertyAccessor only |

## User entity (app-owned phone) — mandatory

QR login only works for users that **already have both**:

| Requirement | Typical field | Rule |
|-------------|---------------|------|
| Mobile number | `phone` (`phone_field`) | Non-empty; E.164 preferred (`+34600111222`) |
| Verification timestamp | `phoneVerifiedAt` (`phone_verified_field`) | Non-null `DateTimeImmutable` — phone was verified at that instant |

The bundle does **not** ship these columns. The app `user_class` must expose them (PropertyAccessor via config). Example:

```php
private ?string $phone = null;                       // E.164 preferred
private ?\DateTimeImmutable $phoneVerifiedAt = null; // null = not verified → QR login forbidden for this user
```

**Hard gate on approve:** `QrLoginUserResolver` must reject users with empty `phone` **or** `phoneVerifiedAt === null`. Unverified numbers never complete a challenge.

Verification / change-phone flows (signup, profile, OTP to prove ownership) are **out of scope** for AuthKit QR login; the app owns them (e.g. `nowo-tech/phone-input-bundle` + SMS notifier). This feature only consumes an already-verified phone.

## Tables

### `auth_kit_qr_login_challenge`

Entity: `QrLoginChallenge`

| Column | Type | Notes |
|--------|------|--------|
| `id` | UUID (string 36) PK | Public opaque id in URLs / QR payload — **never** encode phone/email/user id |
| `public_code` | string(8) unique | Human short code under the QR (accessibility); not sufficient alone to approve |
| `status` | string(16) | `pending` \| `approved` \| `denied` \| `expired` \| `consumed` |
| `user_class` | string(255) nullable | Set **only after** successful approve |
| `user_id` | string(64) nullable | Stringified PK after approve |
| `phone_hint` | string(32) nullable | Masked (`+34 *** ** 12`) written **at approve** for mobile confirmation UI — never in QR |
| `desktop_cookie_hash` | string(64) | Hash of HttpOnly `ak_qr_desk` token set on create (required for status/complete) |
| `desktop_ip_hash` | string(64) | `hash_hmac(sha256, clientIp, kernel.secret)` at create |
| `desktop_ua_hash` | string(64) | Hash of normalized User-Agent at create |
| `desktop_ua_label` | string(128) | Short safe label for phone UI (“Chrome · Windows”) — no raw UA dump |
| `approve_token_hash` | string(64) | Hash of one-time approve secret in QR query `t=` |
| `approve_token_used_at` | datetime_immutable nullable | Set on first successful approve/deny — **single use** |
| `expires_at` | datetime_immutable | `now + challenge_ttl` |
| `approved_at` | datetime_immutable nullable | |
| `consumed_at` | datetime_immutable nullable | Desktop `complete` one-shot |
| `created_at` | datetime_immutable | |
| `updated_at` | datetime_immutable | |

Indexes: `status + expires_at`, unique `public_code`.

**Never stored:** plaintext phone, approve secret, desktop cookie plaintext, full IP, full User-Agent.

### Optional later: `auth_kit_qr_login_audit`

Append-only: challenge id, event (`created`, `scanned`, `otp_sent`, `approved`, `denied`, `consumed`, `expired`), ip hash, created_at. Not required for v1 MVP.

## Routes

All public under `access_control` (and locale-prefixed when `locale.in_path` is `always`/`both`), same pattern as magic / social.

| Method | Path (default) | Name | Actor | Purpose |
|--------|----------------|------|-------|---------|
| `GET` | `/login/qr` | `…_qr_login_start` | Desktop | Create challenge + render QR page (or redirect to show) |
| `GET` | `/login/qr/{id}` | `…_qr_login_show` | Desktop | QR + short code + poller |
| `GET` | `/login/qr/{id}/status` | `…_qr_login_status` | Desktop | JSON `{status, expires_in}` — **no** user PII |
| `GET` | `/login/qr/{id}/complete` | `…_qr_login_complete` | Desktop | If `approved` → `Security::login` + mark `consumed` → redirect |
| `GET` | `/login/qr/{id}/approve` | `…_qr_login_approve` | Phone | Approve form (OTP / session). Query: `?t={approve_secret}` |
| `POST` | `/login/qr/{id}/approve` | `…_qr_login_approve` | Phone | Confirm approval |
| `POST` | `/login/qr/{id}/deny` | `…_qr_login_deny` | Phone | Explicit deny |

**QR payload (privacy — mandatory):** absolute approve URL **only**:

```text
https://app.example/{_locale}/login/qr/{uuid}/approve?t={approve_secret}
```

Allowed query/path params: opaque `uuid` + high-entropy `t`. **Forbidden in QR:** phone, email, user id, public_code, IP, name, profile.

QR image via `QrCodeGeneratorInterface` (optional package / Null → show URL + `public_code` only).

## Sequence

```text
Desktop                         Bundle                         Phone
   |-- GET /login/qr ---------->| create challenge (pending)
   |<-- 302 /login/qr/{id} -----|
   |-- GET show (QR) ---------->|
   |                            |                     user scans QR
   |                            |<-- GET approve?t= --|
   |                            |-- (optional OTP) -->|
   |                            |<-- POST approve ----|
   |                            | status=approved
   |-- GET status (poll) ------>|
   |<-- {approved} -------------|
   |-- GET complete ----------->| Security::login + consumed
   |<-- 302 success ------------|
```

Desktop never sends the phone number. Binding user happens on approve (lookup by verified phone after OTP/session), not at QR creation — **anti-enumeration**: creating a challenge does not require an identifier.

Alternative (stricter): desktop first asks “phone or email”, then QR is bound early. Prefer **unbound-until-approve** for v1 (simpler UX, same as “scan to log in” products).

## Services

| Service | Responsibility |
|---------|----------------|
| `QrLoginGate` | Profile mode enabled |
| `QrLoginChallengeManager` | create / expire / approve / deny / consume + desktop cookie/IP/UA checks |
| `QrLoginUserResolver` | find user by `phone_field` + verified |
| `QrLoginRateLimiter` | Enforce `rate_limit.*` (Symfony RateLimiter factory) |
| `QrLoginStepUpInterface` | App: verify local PIN / biometrics / WebAuthn before approve (`session_step_up`) |
| `QrLoginNotifierInterface` | OTP / deep link SMS (Null + Logging samples; never log secrets) |
| `QrCodeGeneratorInterface` | `pngDataUri(string $payload): string` (Null → text fallback) |

Login completion: `Security::login($user, null, $firewall)` (same as social login).

## Security controls (mandatory)

These four controls are **product requirements**, not optional tips.

### 1. QR photo / shoulder-surf

**Problem:** An attacker photographs the desktop QR and opens the approve URL elsewhere, or races the victim’s challenge.

**Solution (all required in v1):**

| Control | Behaviour |
|---------|-----------|
| Short TTL | Default **90s**, hard clamp **30–180**. Expiry marks `expired`; QR and `t=` die together |
| Single-use approve secret | `approve_token_used_at` set on first successful approve **or** deny; further POSTs fail |
| Single-use login | `complete` transitions `approved` → `consumed` once; replay → login page |
| Desktop challenge cookie | On `start`, set HttpOnly `Secure` cookie `ak_qr_desk` (random 32+ bytes). Store only `desktop_cookie_hash`. `status` and `complete` **require** this cookie — a photo of the QR does **not** let another browser finish login |
| Desktop IP/UA binding | Persist `desktop_ip_hash` + `desktop_ua_hash` at create. On `complete`: `strict` → mismatch fails; `soft` → allow + event; `off` → skip IP/UA (cookie still required) |
| Phone confirmation UI | Approve page shows `desktop_ua_label` (+ coarse geo later if app adds it) so the user can **Deny** an unexpected device |

**Evil QR** (attacker shows their QR, victim scans): cookie binds login to **attacker** desktop by design of the flow — mitigated by step-up (below) + clear device label + short TTL + Deny.

### 2. SIM swap / stolen phone

**Problem:** SMS OTP alone is weak against SIM swap; an unlocked stolen phone can approve sessions.

**Solution:**

| Mode | When to use |
|------|-------------|
| `session_step_up` (**default**) | Phone has an existing AuthKit/app session **and** `QrLoginStepUpInterface::assertUnlocked($request)` succeeds (app PIN, biometric prompt, or WebAuthn). SMS is **not** enough |
| `session` | Logged-in phone without step-up (lower assurance demos only) |
| `phone_otp` | SMS/WhatsApp OTP to verified phone — **explicit low-assurance**; document SIM-swap residual in SECURITY.md |
| `either` | `session_step_up` **or** `phone_otp` |

Bundle contract:

```php
interface QrLoginStepUpInterface
{
    /** @throws AccessDeniedException when unlock fails / cancelled */
    public function assertUnlocked(Request $request): void;
}
```

Default implementation: `NullQrLoginStepUp` throws if `approve_requires` needs step-up (forces apps to wire a real unlock). Demo may ship a “type your PIN” stub.

Stolen-phone residual with step-up: attacker needs biometrics/PIN knowledge — accepted and documented; pair with remote logout / phone re-verify in the app.

### 3. Privacy (QR payload)

**Problem:** Embedding the phone number (or user id) in the QR leaks PII to cameras, logs, and referrer headers.

**Solution:**

- QR / approve URL contains **only** opaque UUID + `t` secret.
- `phone_hint` is computed server-side **after** the approving identity is known and rendered only on the approve HTML page.
- `status` JSON: `{ "status", "expires_in" }` exclusively — never user id, phone, hint, or email.
- Logging notifiers: no QR URLs, no `t`, no full phone (REQ-OBS-001).

### 4. Rate limiting

**Problem:** Challenge spam, approve brute-force, OTP flooding.

**Solution — first-class `QrLoginRateLimiter` (v1, not “hook later”):**

| Bucket | Default policy | On exceed |
|--------|----------------|-----------|
| Create challenge per client IP | `5 / 10 minutes` | 429 + Twig error on start |
| Status poll per IP | `120 / 1 minute` | 429 JSON |
| Approve/deny POST per IP | `20 / 10 minutes` | 429 |
| Approve/deny per challenge id | `5 / challenge` | lock challenge → `denied` or `expired` |
| OTP send per phone (HMAC hash) | `3 / 10 minutes` | skip send + generic flash |

Implementation: `symfony/rate-limiter` (add to `require` when feature ships) with storage `cache.rate_limiter` / framework default. Policies configurable under `qr_login.rate_limit`.

Also dispatch rate-limit events so apps can pair **LoginThrottleBundle** (same pattern as magic login docs).

Logging samples must **never** log approve secrets, full phone, or QR URLs (REQ-OBS-001 style).

## UX

### Desktop — login page

When `qr_login_enabled`:

- Secondary link / button: **“Sign in with phone (QR)”** → `qr_login_start`
- Placement: after social buttons / magic login, same footer density as current login Twig

### Desktop — challenge page (`qr_login_show`)

```
┌─────────────────────────────────────┐
│  Sign in with your phone            │
│                                     │
│         ┌───────────┐               │
│         │  QR CODE  │               │
│         └───────────┘               │
│                                     │
│  Or enter this code on your phone:  │
│           A B 7 K - 2 M 9 Q         │
│                                     │
│  Waiting for approval…  (spinner)   │
│  Expires in 1:42                    │
│                                     │
│  [ Cancel → back to login ]         │
└─────────────────────────────────────┘
```

States (Twig + small progressive-enhancement JS using `poll_interval_ms`):

| State | UI |
|-------|-----|
| `pending` | QR + countdown + “Waiting…” |
| `approved` | Auto-redirect to `complete` (or button “Continue”) |
| `denied` | “Login denied on phone” + retry |
| `expired` | “Code expired” + “Generate new QR” |
| `consumed` | Redirect home / login |

No cards-in-hero marketing; one job: approve this device.

### Phone — approve page

```
┌─────────────────────────────────────┐
│  Approve desktop login?             │
│                                     │
│  Device: Chrome · Windows           │  ← desktop_ua_label (deny if unexpected)
│  Phone:  +34 *** ** 78              │  ← masked hint after identity known
│                                     │
│  [ Unlock with biometrics / PIN ]   │  ← session_step_up (default)
│  [ Enter SMS code ______ ]          │  ← only if phone_otp / either
│                                     │
│  [ Approve ]     [ Deny ]           │
└─────────────────────────────────────┘
```

After approve: “You can close this window. Continue on your computer.”

Copy must stress: **only approve if you started this login on the device shown**.

### Accessibility

- Short code always visible (QR is not the only path).
- Countdown as text, not color-only.
- Approve/Deny as real buttons with CSRF forms (works without JS).

## Login template variables

| Variable | Meaning |
|----------|---------|
| `qr_login_enabled` | Gate true |
| `qr_login_start_route` | Start route name |

Challenge page vars: `challenge_id`, `public_code`, `qr_data_uri` (nullable), `expires_at`, `status_route`, `complete_route`, `poll_interval_ms`.

## Events

| Event | When |
|-------|------|
| `QrLoginChallengeCreatedEvent` | After persist pending |
| `QrLoginApprovedEvent` | After approve |
| `QrLoginDeniedEvent` | After deny |
| `QrLoginCompletedEvent` | After desktop `Security::login` |

Apps subscribe for audit / throttle (same pattern as `MagicLoginRequestedEvent`).

## Phased delivery

| Phase | Scope |
|-------|--------|
| **P0** | Config + entity (incl. desktop cookie/IP/UA hashes) + rate limiter + start/show/status/complete + approve with `session_step_up` (`QrLoginStepUpInterface`) + Twig + privacy-safe QR URL + tests |
| **P1** | `phone_otp` / `either` + `QrLoginNotifierInterface` + Logging/Null notifiers |
| **P2** | QR PNG generator + demo (PIN step-up stub) + CHANGELOG/UPGRADING/SECURITY |
| **P3** | Audit table, coarse geo on approve UI, `public_code` entry without camera |

## Open decisions

1. **Unbound QR (recommended)** vs ask phone/email first on desktop — security section assumes unbound + step-up + device label.
2. Hard dependency on a QR library vs interface + optional package.
3. Whether `configure-security` should auto-add `access_control` for `/login/qr`.
4. Multi-profile: one challenge table shared (filter by `user_class` on approve) — yes for v1.
5. Cookie `SameSite`: `Lax` (default) vs `Strict` (breaks some mobile in-app browsers for unrelated flows; QR approve is a different device so Strict on `ak_qr_desk` is fine).
)
