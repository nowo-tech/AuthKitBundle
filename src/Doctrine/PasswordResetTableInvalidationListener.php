<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Doctrine;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\PasswordReset\ProfilePasswordResetTokenManager;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Repository\PasswordResetRequestRepository;
use Nowo\AuthKitBundle\User\Event\CredentialsChangedEvent;
use Nowo\AuthKitBundle\User\UserIds;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_key_exists;
use function array_unique;
use function array_values;
use function is_string;

/**
 * SEC-004 L6 for `password_reset.storage: table` (1.46.0): the pending reset row of a profile user dies with the
 * identifier or password it was issued for, and with the user. When a flush changes the identifier field or
 * `password_reset.password_field` of a user of a table profile, or deletes that user, its rows are deleted.
 *
 * The rows are deleted when the flush starts (`onFlush`, before the flush transaction): if that flush then fails, the
 * credential is gone anyway (fail safe: the user asks for a new one). Changes made outside Doctrine: dispatch
 * {@see CredentialsChangedEvent} (it clears the row through {@see PasswordResetTokenManagerInterface}). Registered
 * only when a profile uses the table. Stateless.
 */
final readonly class PasswordResetTableInvalidationListener
{
    public function __construct(
        private ProfileRegistry $profileRegistry,
        private PasswordResetRequestRepository $requests,
        private PropertyAccessorInterface $propertyAccessor,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            $profile = $this->tableProfile($entity);
            if (!$profile instanceof ProfileSettings) {
                continue;
            }

            $changeSet       = $unitOfWork->getEntityChangeSet($entity);
            $identifierField = $profile->userIdentifierField;
            if (!array_key_exists($identifierField, $changeSet) && !array_key_exists((string) ($profile->passwordReset['password_field'] ?? 'password'), $changeSet)) {
                continue;
            }

            $identifiers = [$this->identifier($entity, $identifierField)];
            if (array_key_exists($identifierField, $changeSet)) {
                $identifiers[] = $changeSet[$identifierField][0] ?? null;
            }
            $this->delete($entity, $identifiers);
        }

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            $profile = $this->tableProfile($entity);
            if ($profile instanceof ProfileSettings) {
                $this->delete($entity, [$this->identifier($entity, $profile->userIdentifierField)]);
            }
        }
    }

    private function tableProfile(object $entity): ?ProfileSettings
    {
        $profile = $this->profileRegistry->resolveForObject($entity);

        return $profile instanceof ProfileSettings && ProfilePasswordResetTokenManager::usesTable($profile) ? $profile : null;
    }

    /**
     * @param list<mixed> $identifiers
     */
    private function delete(object $entity, array $identifiers): void
    {
        $kept = $entity instanceof UserInterface ? [$entity->getUserIdentifier()] : [];
        foreach ($identifiers as $identifier) {
            if (is_string($identifier) && $identifier !== '') {
                $kept[] = $identifier;
            }
        }

        // @igor-ignore - Stateless DBAL delete of the user's reset rows.
        $this->requests->delete(UserIds::className($entity), UserIds::of($entity, $this->propertyAccessor), array_values(array_unique($kept)));
    }

    private function identifier(object $entity, string $field): mixed
    {
        return $this->propertyAccessor->isReadable($entity, $field) ? $this->propertyAccessor->getValue($entity, $field) : null;
    }
}
