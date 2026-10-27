<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Doctrine;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;

use function array_key_exists;
use function is_string;

/**
 * SEC-004 L6: a pending password reset credential dies with the identifier or password it was issued for. When a
 * profile user's identifier or password changes outside the reset flow (profile edit, admin, social linking), the
 * stored reset token / code and its expiry are cleared in the same flush.
 */
final readonly class PasswordResetTokenInvalidationListener
{
    public function __construct(
        private ProfileRegistry $profileRegistry,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork    = $entityManager->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            $profile = $this->profileRegistry->resolveForObject($entity);
            if (!$profile instanceof ProfileSettings) {
                continue;
            }

            $tokenField   = $profile->passwordReset['token_field'] ?? null;
            $expiresField = $profile->passwordReset['token_expires_field'] ?? null;
            $metadata     = $entityManager->getClassMetadata($entity::class);
            if (!is_string($tokenField) || !is_string($expiresField)
                || !$metadata->hasField($tokenField) || !$metadata->hasField($expiresField)
            ) {
                continue;
            }

            $changeSet = $unitOfWork->getEntityChangeSet($entity);
            $changed   = array_key_exists($profile->userIdentifierField, $changeSet) || array_key_exists('password', $changeSet);
            // The reset flow itself writes the token fields (issue, consume): leave those flushes alone.
            if (!$changed || array_key_exists($tokenField, $changeSet) || $metadata->getFieldValue($entity, $tokenField) === null) {
                continue;
            }

            $metadata->setFieldValue($entity, $tokenField, null);
            $metadata->setFieldValue($entity, $expiresField, null);
            $unitOfWork->recomputeSingleEntityChangeSet($metadata, $entity);
        }
    }
}
