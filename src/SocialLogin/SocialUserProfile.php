<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

/**
 * Normalized profile returned by an OAuth provider userinfo endpoint.
 *
 * @phpstan-type ProfileArray array{id: string, email: ?string, name: ?string, emailVerified: ?bool, raw: array<string, mixed>}
 */
final readonly class SocialUserProfile
{
    /**
     * @param array<string, mixed> $raw
     * @param bool|null $emailVerified true/false from IdP; null when the provider did not assert verification
     */
    public function __construct(
        public string $id,
        public ?string $email,
        public ?string $name,
        public array $raw,
        public ?bool $emailVerified = null,
    ) {
    }
}
