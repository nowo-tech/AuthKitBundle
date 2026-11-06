<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Availability;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asks the checkers configured per profile and feature ({@see FeatureAvailabilityCheckerInterface}). No checker means
 * no opinion: every caller then behaves as before 1.37.
 *
 * A checker that throws counts as "not available" (logged): the feature fails closed and the sign-in page, which asks
 * on every view, stays up for password sign-in.
 */
final class FeatureAvailability
{
    public const MAGIC_LOGIN = 'magic_login';

    public const PASSWORD_RESET = 'password_reset';

    public function __construct(
        private readonly ?ContainerInterface $checkers = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return bool|null the checker's answer, or null when the feature has no checker for this profile
     */
    public function decision(string $feature, string $profile): ?bool
    {
        $key = $feature . '.' . $profile;
        if (!$this->checkers instanceof ContainerInterface || !$this->checkers->has($key)) {
            return null;
        }

        try {
            /** @var FeatureAvailabilityCheckerInterface $checker */
            $checker = $this->checkers->get($key);

            return $checker->isAvailable($profile);
        } catch (Throwable $exception) {
            // The class only: a host exception message may carry a DSN or other secrets.
            $this->logger?->error('AuthKit availability checker failed: {feature} treated as unavailable for profile {profile}.', [
                'feature'   => $feature,
                'profile'   => $profile,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    /**
     * True only when a checker exists and says no.
     */
    public function isUnavailable(string $feature, string $profile): bool
    {
        return $this->decision($feature, $profile) === false;
    }
}
