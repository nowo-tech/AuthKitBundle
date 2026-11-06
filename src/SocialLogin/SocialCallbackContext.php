<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use Nowo\AuthKitBundle\Profile\ProfileSettings;
use SensitiveParameter;

/**
 * A social callback that passed every check: the OAuth state matched this session and was burned, PKCE and the code
 * exchange succeeded and the provider returned this identity. Nothing else has happened: no account was linked or
 * created and nobody is signed in. `tokens` holds the provider's access token: never log it.
 */
final readonly class SocialCallbackContext
{
    /**
     * @param array{access_token: string, refresh_token: ?string, expires_in: ?int, raw: array<string, mixed>} $tokens as returned by {@see OAuth2Client::exchangeCode()}, accepted by {@see SocialAccountLinker::linkOrCreate()}
     */
    public function __construct(
        public ProfileSettings $profile,
        public string $provider,
        public SocialUserProfile $socialProfile,
        #[SensitiveParameter]
        public array $tokens,
    ) {
    }
}
