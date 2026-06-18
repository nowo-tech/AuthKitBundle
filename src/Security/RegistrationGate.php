<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Enum\RegistrationMode;

/**
 * Determines whether registration is currently allowed.
 */
final class RegistrationGate
{
    private readonly RegistrationMode $registrationMode;

    /**
     * @param class-string $userClass
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $userClass,
        string $registrationMode,
    ) {
        $this->registrationMode = RegistrationMode::from($registrationMode);
    }

    public function isRegistrationAllowed(): bool
    {
        return match ($this->registrationMode) {
            RegistrationMode::Disabled      => false,
            RegistrationMode::Always        => true,
            RegistrationMode::FirstUserOnly => $this->countUsers() === 0,
        };
    }

    private function countUsers(): int
    {
        return $this->entityManager
            ->getRepository($this->userClass)
            ->count([]);
    }
}
