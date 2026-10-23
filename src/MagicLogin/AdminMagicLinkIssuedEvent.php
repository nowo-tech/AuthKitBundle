<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MagicLogin;

use DateTimeImmutable;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Dispatched by {@see AdminMagicLinkIssuer} for every link it issues — the host records it in its audit trail (who,
 * for whom, shown or e-mailed). The link itself is never part of the event.
 */
final class AdminMagicLinkIssuedEvent
{
    public const DELIVERY_SHOWN = 'shown';
    public const DELIVERY_EMAIL = 'email';

    public function __construct(
        public readonly UserInterface $issuer,
        public readonly UserInterface $target,
        public readonly string $delivery,
        public readonly DateTimeImmutable $expiresAt,
    ) {
    }
}
