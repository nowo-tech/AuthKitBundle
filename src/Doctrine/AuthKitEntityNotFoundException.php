<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Doctrine;

use RuntimeException;

use function sprintf;

/**
 * 1.55.0 (REQ-ARCH-001 rule 6): an AuthKit row handed to an AuthKit service no longer exists (deleted since the caller
 * loaded it), so nothing was written. Typically an invitation or a QR login challenge that the application loaded
 * through its own entity manager and that was purged, or deleted by another request, before the call.
 */
final class AuthKitEntityNotFoundException extends RuntimeException
{
    /**
     * @param class-string $entityClass The AuthKit entity class
     * @param string $id The identifier the caller's instance carries
     */
    public function __construct(
        public readonly string $entityClass,
        public readonly string $id,
    ) {
        // The identifier stays out of the message (it may reach logs); read it from $id.
        parent::__construct(sprintf('The %s row no longer exists; nothing was written. Load it again before retrying.', $entityClass));
    }
}
