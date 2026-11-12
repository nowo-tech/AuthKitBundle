<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support\Ports;

use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\User\UserLookupInterface;

/** Implements the deprecated lookup next to the 1.46 directory (build-pass fixtures). */
final class BothUsersHost extends UserDirectoryHost implements UserLookupInterface
{
    public function findByIdentifier(ProfileSettings $profile, string $identifier): null
    {
        return null;
    }

    public function findById(ProfileSettings $profile, string $id): null
    {
        return null;
    }
}
