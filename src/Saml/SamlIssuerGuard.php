<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Saml;

use Doctrine\ORM\Event\OnFlushEventArgs;
use LogicException;
use Nowo\AuthKitBundle\Entity\SamlProvider;
use Nowo\AuthKitBundle\Repository\SocialLoginAccountRepository;
use Nowo\AuthKitBundle\SocialLogin\SocialCredentialIssuerGuard;

use function is_array;
use function is_string;
use function sprintf;

/**
 * The SAML twin of {@see SocialCredentialIssuerGuard} (2.10.0): a provider key is one subject namespace only together
 * with its IdP entity id (the same NameID at another identity provider is somebody else). The sign-in already refuses a
 * link made under another entity id; this guard refuses to change the entity id of a row while `saml:<key>` links exist
 * (unlink them first, or use a new provider key):
 *
 * - {@see self::entityIdChangeError()} for administration screens and {@see SamlIdpMetadataImporter::import()};
 * - `onFlush` (always registered): an update of `idp_entity_id` with links is refused before the flush writes anything.
 *
 * Stateless (worker-safe).
 */
final class SamlIssuerGuard
{
    public const ERROR = 'The IdP entity id of the SAML provider "%s" cannot change while accounts are linked to it: they were linked under the current entity id. Unlink them first, or add the new identity provider under a new provider key.';

    public function __construct(
        private readonly SocialLoginAccountRepository $accounts,
    ) {
    }

    /**
     * @return string|null why the change is refused, or null when it is allowed (same entity id, a row never set up, or
     *                     no linked account)
     */
    public function entityIdChangeError(SamlProvider $provider, string $newEntityId): ?string
    {
        return $this->changeError($provider->getProvider(), $provider->getIdpEntityId(), $newEntityId);
    }

    /**
     * @throws LogicException when the change is refused
     */
    public function assertEntityIdChangeAllowed(SamlProvider $provider, string $newEntityId): void
    {
        $error = $this->entityIdChangeError($provider, $newEntityId);
        if ($error !== null) {
            throw new LogicException($error);
        }
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();
        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof SamlProvider) {
                continue;
            }
            $change = $unitOfWork->getEntityChangeSet($entity)['idpEntityId'] ?? null;
            $old    = is_array($change) ? ($change[0] ?? null) : null;
            $new    = is_array($change) ? ($change[1] ?? null) : null;
            $error  = is_string($old) && is_string($new) ? $this->changeError($entity->getProvider(), $old, $new) : null;
            if ($error !== null) {
                throw new LogicException($error);
            }
        }
    }

    private function changeError(string $provider, string $old, string $new): ?string
    {
        if ($old === $new || $old === '' || $provider === '' || $this->accounts->count(['provider' => SamlProvider::accountProvider($provider)]) === 0) {
            return null;
        }

        return sprintf(self::ERROR, $provider);
    }
}
