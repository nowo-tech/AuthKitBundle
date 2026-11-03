<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\Security;

use Deprecated;
use Nowo\AuthKitBundle\Mfa\MfaLoginCompleter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function bin2hex;
use function random_bytes;

/**
 * Firewall token of a sign-in waiting for its second step (1.34). It wraps the token of the first factor (restored by
 * {@see MfaLoginCompleter} once the code is valid) and has **no roles**, so `access_control`
 * and voters refuse it everywhere; the containment listener sends every other request to the challenge.
 *
 * It does not extend AbstractToken on purpose: the firewall's ContextListener compares an AbstractToken's roles with
 * the refreshed user and would sign a role-less token out. UserKit (and any package reading the attribute
 * `nowo.authentication_pending`) does not register the session before the token is final.
 */
final class MfaPendingToken implements TokenInterface
{
    /** Token attribute read by UserKit 1.11 (PendingAuthenticationTokenInterface::ATTRIBUTE). */
    public const PENDING_ATTRIBUTE = 'nowo.authentication_pending';

    /** The user has MFA: enter a code. */
    public const MODE_CHALLENGE = 'challenge';

    /** The user must enrol now (required, grace over). */
    public const MODE_SETUP = 'setup';

    /** @var array<string, mixed> */
    private array $attributes = [self::PENDING_ATTRIBUTE => true];

    private bool $rememberMe = false;

    private ?string $targetPath = null;

    /** Random id of this pending sign-in (server-side attempt counter, DD-M1). */
    private string $id;

    /** This pending sign-in enrolled the second factor itself (forced enrolment, DD-L1). */
    private bool $enrolled = false;

    public function __construct(
        private TokenInterface $inner,
        private readonly string $firewall,
        private readonly string $profile,
        private readonly string $firstFactor,
        private readonly string $mode,
        private readonly int $createdAt,
    ) {
        $this->id = bin2hex(random_bytes(16));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function enrolled(): bool
    {
        return $this->enrolled;
    }

    public function markEnrolled(): void
    {
        // @igor-ignore - Token of one session (serialized into it), not a shared service.
        $this->enrolled = true;
    }

    public function inner(): TokenInterface
    {
        return $this->inner;
    }

    public function firewall(): string
    {
        return $this->firewall;
    }

    public function profile(): string
    {
        return $this->profile;
    }

    public function firstFactor(): string
    {
        return $this->firstFactor;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function createdAt(): int
    {
        return $this->createdAt;
    }

    public function wantsRememberMe(): bool
    {
        return $this->rememberMe;
    }

    public function requestRememberMe(): void
    {
        // @igor-ignore - Token of one session (serialized into it), not a shared service.
        $this->rememberMe = true;
    }

    public function targetPath(): ?string
    {
        return $this->targetPath;
    }

    public function setTargetPath(?string $path): void
    {
        // @igor-ignore - Token of one session (serialized into it), not a shared service.
        $this->targetPath = $path;
    }

    public function __toString(): string
    {
        return 'MfaPendingToken(user="' . $this->getUserIdentifier() . '")';
    }

    public function getUserIdentifier(): string
    {
        return $this->inner->getUserIdentifier();
    }

    /**
     * No roles while pending.
     *
     * @return list<string>
     */
    public function getRoleNames(): array
    {
        return [];
    }

    public function getUser(): ?UserInterface
    {
        return $this->inner->getUser();
    }

    public function setUser(UserInterface $user): void
    {
        $this->inner->setUser($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function setAttributes(array $attributes): void
    {
        // @igor-ignore - Token of one session (serialized into it), not a shared service.
        $this->attributes = [self::PENDING_ATTRIBUTE => true] + $attributes;
    }

    public function hasAttribute(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    public function getAttribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function setAttribute(string $name, mixed $value): void
    {
        if ($name !== self::PENDING_ATTRIBUTE) {
            // @igor-ignore - Token of one session (serialized into it), not a shared service.
            $this->attributes[$name] = $value;
        }
    }

    /**
     * @return array<int, mixed>
     */
    /**
     * Nothing to erase (Symfony < 8 interface method; no-op).
     */
    #[Deprecated]
    public function eraseCredentials(): void
    {
    } // @codeCoverageIgnore

    public function __serialize(): array
    {
        return [$this->inner, $this->firewall, $this->profile, $this->firstFactor, $this->mode, $this->createdAt, $this->attributes, $this->rememberMe, $this->targetPath, $this->id, $this->enrolled];
    }

    /**
     * @param array<int, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        // @igor-ignore - Token of one session (serialized into it), not a shared service.
        [$this->inner, $this->firewall, $this->profile, $this->firstFactor, $this->mode, $this->createdAt, $this->attributes, $this->rememberMe, $this->targetPath, $this->id, $this->enrolled] = $data;
    }
}
