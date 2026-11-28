<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

/**
 * Normalized profile returned by an OAuth provider userinfo endpoint (2.10.0: or by a checked SAML assertion).
 *
 * @phpstan-type ProfileArray array{id: string, email: ?string, name: ?string, emailVerified: ?bool, raw: array<string, mixed>}
 */
final readonly class SocialUserProfile
{
    /**
     * @param array<string, mixed> $raw
     * @param bool|null $emailVerified true/false from IdP; null when the provider did not assert verification
     * @param string|null $issuer OpenID Connect issuer of the subject (2.3.0, no trailing slash); null for OAuth2
     * @param array<string, mixed>|null $idTokenClaims 2.7.0: the claims of the validated ID token only (signature, iss,
     *                                                 aud, exp/iat and nonce checked; `nonce` removed), never merged with
     *                                                 userinfo: read `amr`, `acr`, `hd`, `tid` from here. Null for OAuth2
     * @param array{issuer: string, name_id: ?string, name_id_format: ?string, session_index: ?string, authn_context_class_refs: list<string>, attributes: array<string, list<string>>, groups: list<string>, assertion_id: ?string}|null $samlAssertion
     *                                                 2.10.0: the checked SAML assertion of a SAML sign-in (provider
     *                                                 `saml:<key>`), never stored; null for OAuth2 / OpenID Connect
     */
    public function __construct(
        public string $id,
        public ?string $email,
        public ?string $name,
        public array $raw,
        public ?bool $emailVerified = null,
        public ?string $issuer = null,
        public ?array $idTokenClaims = null,
        public ?array $samlAssertion = null,
    ) {
    }
}
