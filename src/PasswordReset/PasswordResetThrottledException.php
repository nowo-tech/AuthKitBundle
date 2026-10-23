<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Thrown by a {@see PasswordResetTokenManagerInterface::createForUser()} implementation that refuses to issue a new
 * credential because the user requested one too recently (e.g. symfonycasts' TooManyPasswordRequestsException).
 *
 * {@see PasswordResetRequestHandler} catches it: no notification is sent, the outcome is marked as throttled and the
 * request page answers exactly as for any other request (no user enumeration) unless the host reads the outcome
 * (password_reset.after_request_route + {@see PasswordResetCheckEmail}).
 */
final class PasswordResetThrottledException extends RuntimeException
{
    public function __construct(
        private readonly ?DateTimeImmutable $availableAt = null,
        string $message = 'A password reset was already requested recently.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * When the next request will be accepted (null: unknown).
     */
    public function getAvailableAt(): ?DateTimeImmutable
    {
        return $this->availableAt;
    }
}
