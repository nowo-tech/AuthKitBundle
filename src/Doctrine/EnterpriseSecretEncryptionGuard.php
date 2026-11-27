<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorInterface;
use Nowo\DoctrineEncryptBundle\Subscribers\DoctrineEncryptSubscriber;

/**
 * 2.8.0 (fail closed): refuses to insert or update an enterprise credential (`enterprise_sso` true) that holds a client
 * secret unless nowo-tech/doctrine-encrypt-bundle encrypts on the entity manager being flushed: its listener is
 * registered for `onFlush` on that manager's event manager and has an encryptor (the `#[Encrypted]` client secret is
 * then written encrypted). Thrown from `onFlush`, before the flush opens its transaction: nothing is written and the
 * manager stays open.
 *
 * Consumer credentials (`enterprise_sso` false) and enterprise credentials without a secret (public clients) are not
 * affected. Turned off only by `nowo_auth_kit.doctrine.require_enterprise_secret_encryption: false` (tests and demos).
 * Stateless (worker-safe).
 */
final class EnterpriseSecretEncryptionGuard
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em         = $args->getObjectManager();
        $unitOfWork = $em->getUnitOfWork();
        foreach ([$unitOfWork->getScheduledEntityInsertions(), $unitOfWork->getScheduledEntityUpdates()] as $entities) {
            foreach ($entities as $entity) {
                if ($entity instanceof SocialLoginCredential && $entity->isEnterpriseSso() && $entity->getClientSecret() !== '' && !self::encrypts($em)) {
                    throw EnterpriseSecretNotEncryptedException::forProvider($entity->getProvider());
                }
            }
        }
    }

    /**
     * Whether DoctrineEncrypt's listener encrypts the flushes of this manager.
     */
    public static function encrypts(EntityManagerInterface $em): bool
    {
        foreach ($em->getEventManager()->getListeners(Events::onFlush) as $listener) {
            if ($listener instanceof DoctrineEncryptSubscriber && $listener->getEncryptor() instanceof EncryptorInterface) {
                return true;
            }
        }

        return false;
    }
}
