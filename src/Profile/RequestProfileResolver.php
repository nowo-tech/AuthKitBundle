<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Profile;

use Symfony\Component\HttpFoundation\Request;

use function is_string;

/**
 * Resolves the active Auth Kit profile from the current HTTP request.
 */
final class RequestProfileResolver
{
    public const REQUEST_ATTRIBUTE = '_auth_kit_profile';

    public function __construct(private readonly ProfileRegistry $profileRegistry)
    {
    }

    public function resolve(Request $request): ProfileSettings
    {
        $profileName = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        if (is_string($profileName) && $profileName !== '') {
            return $this->profileRegistry->getByName($profileName);
        }

        return $this->profileRegistry->getDefault();
    }
}
