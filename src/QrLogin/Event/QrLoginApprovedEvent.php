<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\QrLogin\Event;

use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Port\AuthUserRefs;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Nowo\AuthKitBundle\ValueObject\QrLoginChallengeRef;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Dispatched after a QR login challenge is approved by the phone.
 */
final readonly class QrLoginApprovedEvent
{
    /**
     * @param QrLoginChallenge $challenge @deprecated since 1.46.0, use challengeRef() or challengeId(); removed in 2.0
     * @param UserInterface $user @deprecated since 1.46.0, use userRef(); removed in 2.0
     */
    public function __construct(
        public QrLoginChallenge $challenge,
        public UserInterface $user,
        public string $profileName,
    ) {
    }

    /**
     * The challenge id (1.46.0, REQ-ARCH-001 rule 4).
     */
    public function challengeId(): string
    {
        return $this->challenge->getId();
    }

    /**
     * The challenge, framework-free and without secrets (1.46.0).
     */
    public function challengeRef(): QrLoginChallengeRef
    {
        return QrLoginChallengeRef::fromChallenge($this->challenge);
    }

    /**
     * The user, framework-free (1.46.0, REQ-ARCH-001 rule 3).
     */
    public function userRef(): AuthUserRef
    {
        return AuthUserRefs::fromUser($this->user);
    }

    /**
     * The `$challenge` value (1.55.0).
     *
     * @deprecated since 1.55.0, use challengeRef() or challengeId(); removed in 2.0 with the entity-typed property (REQ-ARCH-001 rule 3)
     */
    public function challenge(): QrLoginChallenge
    {
        return $this->challenge;
    }

    /**
     * The `$user` value (1.55.0).
     *
     * @deprecated since 1.55.0, use userRef(); removed in 2.0 with the framework-typed property (REQ-ARCH-001 rule 3)
     */
    public function user(): UserInterface
    {
        return $this->user;
    }

    /**
     * The `$profileName` value (1.55.0): read it here; same value as the property.
     */
    public function profileName(): string
    {
        return $this->profileName;
    }
}
