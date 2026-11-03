<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Result of {@see AccountVault::switch()}.
 */
final readonly class SwitchOutcome
{
    public const SWITCHED = 'switched';

    public const REVIVED = 'revived';

    public const GONE = 'gone';

    public const REFUSED = 'refused';

    public const NOT_FOUND = 'not_found';

    /**
     * @param array<string, mixed> $parameters translation parameters of `$message`
     */
    private function __construct(
        public string $status,
        public ?UserInterface $user = null,
        public ?string $message = null,
        public array $parameters = [],
    ) {
    }

    public static function switched(UserInterface $user): self
    {
        return new self(self::SWITCHED, $user);
    }

    public static function revived(UserInterface $user): self
    {
        return new self(self::REVIVED, $user);
    }

    public static function gone(): self
    {
        return new self(self::GONE, null, 'accounts.flash.gone');
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function refused(string $message, array $parameters = []): self
    {
        return new self(self::REFUSED, null, $message, $parameters);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, null, 'accounts.flash.unknown');
    }
}
