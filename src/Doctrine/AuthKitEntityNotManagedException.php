<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Doctrine;

use LogicException;

use function sprintf;

/**
 * 1.55.0 (REQ-ARCH-001 rule 6): a low-level AuthKit save (`InvitationRepository::save()`,
 * `QrLoginChallengeRepository::save()`, `MfaStore::save()`) received an existing AuthKit row that AuthKit's entity
 * manager does not hold, typically one loaded through the application's own manager. Saving it would insert it a second
 * time, so nothing is written. Hand the row to the AuthKit service instead (it loads it again by id), or load it through
 * the AuthKit repository service.
 */
final class AuthKitEntityNotManagedException extends LogicException
{
    /**
     * @param class-string $entityClass The AuthKit entity class
     * @param string $id The identifier the caller's instance carries
     */
    public function __construct(
        public readonly string $entityClass,
        public readonly string $id,
    ) {
        parent::__construct(sprintf('The %s row "%s" is not managed by AuthKit\'s entity manager (loaded through another manager?); saving it would insert it again. Pass it to the AuthKit service, which loads it again by id, or load it through the AuthKit repository service.', $entityClass, $id));
    }
}
