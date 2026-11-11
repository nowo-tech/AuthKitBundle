<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security\LoginTarget;

/**
 * Host port of the post-login target guard (1.44): decides a target before the configured rules.
 *
 * Services implementing it are autoconfigured (tag `nowo_auth_kit.login_target_voter`, `priority` attribute honoured).
 * The first voter that returns a boolean wins: `true` keeps the target, `false` replaces it with the fallback; `null`
 * abstains and the next voter (then the configured rules) decides.
 *
 * A voter only sees targets that already passed the same-origin checks (no other host, scheme, `//`, `\`, user info or
 * control characters): it can never allow an open redirect.
 */
interface LoginTargetVoterInterface
{
    public const TAG = 'nowo_auth_kit.login_target_voter';

    public function vote(LoginTarget $target): ?bool;
}
