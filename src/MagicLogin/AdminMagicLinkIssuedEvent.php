<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MagicLogin;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Port\AuthUserRefs;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Dispatched by {@see AdminMagicLinkIssuer} for every link it issues — the host records it in its audit trail (who,
 * for whom, shown or e-mailed). The link itself is never part of the event.
 */
final class AdminMagicLinkIssuedEvent
{
    public const DELIVERY_SHOWN = 'shown';
    public const DELIVERY_EMAIL = 'email';

    /**
     * @param UserInterface $issuer @deprecated since 1.46.0, use issuerRef(); removed in 2.0
     * @param UserInterface $target @deprecated since 1.46.0, use targetRef(); removed in 2.0
     */
    public function __construct(
        public readonly UserInterface $issuer,
        public readonly UserInterface $target,
        public readonly string $delivery,
        public readonly DateTimeImmutable $expiresAt,
    ) {
    }

    /**
     * The administrator who issued the link, framework-free (1.46.0).
     */
    public function issuerRef(): AuthUserRef
    {
        return AuthUserRefs::fromUser($this->issuer);
    }

    /**
     * The user the link signs in, framework-free (1.46.0).
     */
    public function targetRef(): AuthUserRef
    {
        return AuthUserRefs::fromUser($this->target);
    }
}
