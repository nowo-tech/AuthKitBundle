<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Availability;

/**
 * Host service named by `magic_login.availability_checker` or `password_reset.availability_checker` (1.37): whether a
 * feature can work right now (for example: a mail or SMS transport is configured). False removes the sign-in page link
 * and answers 404 on the feature's routes.
 *
 * Called on every sign-in page: keep it cheap, stateless (worker safe) and free of per-user decisions, because it only
 * receives the profile name. It is an operational gate, not a security control: see docs/SECURITY.md.
 */
interface FeatureAvailabilityCheckerInterface
{
    public function isAvailable(string $profile): bool;
}
