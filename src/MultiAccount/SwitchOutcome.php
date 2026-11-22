<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount;

use Nowo\AuthKitBundle\Port\AuthUserRefs;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
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
     * @param UserInterface|null $user not public since 2.0: read userRef()
     * @param array<string, mixed> $parameters translation parameters of `$message`
     */
    private function __construct(
        public string $status,
        private ?UserInterface $user = null,
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

    /**
     * The account switched to, framework-free (1.46.0); null unless switched or revived.
     */
    public function userRef(): ?AuthUserRef
    {
        return AuthUserRefs::fromNullableUser($this->user);
    }

    /**
     * The `$status` value (1.55.0): read it here; same value as the property.
     */
    public function status(): string
    {
        return $this->status;
    }

    /**
     * The `$message` value (1.55.0): read it here; same value as the property.
     */
    public function message(): ?string
    {
        return $this->message;
    }

    /**
     * The `$parameters` value (1.55.0): read it here; same value as the property.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }
}
