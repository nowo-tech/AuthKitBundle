<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa;

use InvalidArgumentException;

use function mb_strlen;
use function sprintf;

/**
 * Stable reference of a user for MFA (1.34): the Doctrine root entity class and identifier, so an e-mail / username
 * change can never detach a second factor ({@see MfaOwnerResolver}).
 */
final readonly class MfaOwner
{
    public const MAX_LENGTH = 191;

    public function __construct(
        public string $type,
        public string $id,
    ) {
        if ($type === '' || $id === '' || mb_strlen($type) > self::MAX_LENGTH || mb_strlen($id) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('An MFA owner needs a non-empty type and id of at most %d characters.', self::MAX_LENGTH));
        }
    }

    public function key(): string
    {
        return $this->type . '#' . $this->id;
    }
}
