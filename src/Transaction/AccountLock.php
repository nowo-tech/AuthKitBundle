<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Transaction;

use Closure;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Security\UserIdentifierMatcher;

/**
 * 1.50.0 (Beacon spec 239): the named locks of the account-creating units of work ({@see AuthKitNamedLock}), always
 * taken in this order, before any row lock:
 *
 * 1. the social identity (`provider`, subject, user class) being linked;
 * 2. the whole user class, for the first account of a `registration_mode: first_user_only` profile: the registration
 *    page always takes it there (instead of 3), a social sign-up when it is about to create that account (audit
 *    QG-M1), so one of them commits first and the other one's "no account yet" check sees it;
 * 3. the account: the identifier folded like {@see UserIdentifierMatcher::normalize()} (NFC, lower case) in the user
 *    class.
 *
 * So two requests creating an account for one identifier (registration, invitation acceptance, social sign-up,
 * administrator creation, in any mix) run one after the other, and the second one's identifier checks see the first
 * one's account. Stateless.
 *
 * @internal
 */
final class AccountLock
{
    /**
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    public static function forIdentifier(AuthKitTransactionRunner $transactions, ProfileSettings $profile, ?string $identifier, Closure $work): mixed
    {
        if ($identifier === null || $identifier === '') {
            return $work();
        }

        return AuthKitNamedLock::hold($transactions->connection(), 'account:' . $profile->userClass . "\0" . UserIdentifierMatcher::normalize($identifier), $work);
    }

    /**
     * Every account of the user class (`registration_mode: first_user_only`).
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    public static function forUserClass(AuthKitTransactionRunner $transactions, ProfileSettings $profile, Closure $work): mixed
    {
        return AuthKitNamedLock::hold($transactions->connection(), 'accounts:' . $profile->userClass, $work);
    }

    /**
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    public static function forSocialIdentity(AuthKitTransactionRunner $transactions, string $provider, string $subject, string $userClass, Closure $work): mixed
    {
        return AuthKitNamedLock::hold($transactions->connection(), 'social:' . $provider . "\0" . $subject . "\0" . $userClass, $work);
    }
}
