<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Profile;

use InvalidArgumentException;

use function sprintf;

final class UnknownProfileException extends InvalidArgumentException
{
    public function __construct(string $profileName)
    {
        parent::__construct(sprintf('Unknown Auth Kit profile "%s".', $profileName));
    }
}
