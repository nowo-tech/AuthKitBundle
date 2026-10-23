<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;

use function is_array;
use function is_int;
use function is_string;

/**
 * Session payload for the host "check your email" page (password_reset.after_request_route).
 *
 * The request page stores it after every valid submit (same data whether or not the identifier exists, except
 * "throttled", see {@see PasswordResetThrottledException}); the host page reads it with {@see fromRequest()} and
 * typically shows the credential lifetime (tokenTtl seconds).
 */
final readonly class PasswordResetCheckEmail
{
    public const SESSION_KEY = '_nowo_auth_kit.password_reset.check_email';

    public function __construct(
        public string $profile,
        public int $tokenTtl,
        public bool $throttled = false,
        public ?DateTimeImmutable $availableAt = null,
    ) {
    }

    public static function fromRequest(Request $request): ?self
    {
        if (!$request->hasSession()) {
            return null;
        }

        $data = $request->getSession()->get(self::SESSION_KEY);
        if (!is_array($data) || !is_string($data['profile'] ?? null) || !is_int($data['token_ttl'] ?? null)) {
            return null;
        }

        $availableAt = $data['available_at'] ?? null;

        return new self(
            $data['profile'],
            $data['token_ttl'],
            ($data['throttled'] ?? false) === true,
            is_int($availableAt) ? new DateTimeImmutable('@' . $availableAt) : null,
        );
    }

    public function store(Request $request): void
    {
        if (!$request->hasSession()) {
            return;
        }

        $request->getSession()->set(self::SESSION_KEY, [
            'profile'      => $this->profile,
            'token_ttl'    => $this->tokenTtl,
            'throttled'    => $this->throttled,
            'available_at' => $this->availableAt?->getTimestamp(),
        ]);
    }
}
