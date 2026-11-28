<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Saml;

use Nowo\AuthKitBundle\Entity\SamlProvider;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Security\ExactIdentifier;
use Nowo\AuthKitBundle\SocialLogin\SocialUserProfile;
use OneLogin\Saml2\Constants;

use function array_filter;
use function array_values;
use function filter_var;
use function implode;
use function strlen;
use function trim;

use const FILTER_VALIDATE_EMAIL;

/**
 * Turns a checked assertion into the {@see SocialUserProfile} of the social pipeline (2.10.0) with the provider row's
 * attribute mapping:
 *
 * - subject: the `subject` attribute, else the NameID unless it is transient (a transient NameID changes at every
 *   sign-in and would link a new identity each time); must pass {@see ExactIdentifier} (≤ 255 bytes);
 * - e-mail: the `email` attribute, else an `emailAddress` NameID; required; verified only when the row says the IdP is
 *   authoritative for it (`email_verified`);
 * - name: `display_name`, else `first_name` + `last_name`; groups: every value of `groups` (for host handlers);
 * - issuer: the IdP entity id (stored on the link; a link made under another entity id no longer signs in).
 *
 * `raw` (stored on the link) keeps the mapped values only; the whole assertion is in `samlAssertion` for host handlers
 * and never stored. Stateless.
 *
 * @internal
 */
final class SamlProfileMapper
{
    /**
     * @throws SamlFailure `unusable_subject`, `missing_email`
     */
    public function map(SamlProvider $provider, SamlAssertion $assertion): SocialUserProfile
    {
        $mapping = $provider->getAttributeMapping();
        $subject = isset($mapping['subject']) ? $this->first($assertion, $mapping['subject']) : null;
        if ($subject === null && $assertion->nameIdFormat !== Constants::NAMEID_TRANSIENT) {
            $subject = $assertion->nameId;
        }
        if ($subject === null || !ExactIdentifier::usable($subject, SocialLoginAccount::PROVIDER_USER_ID_MAX_LENGTH)) {
            throw new SamlFailure(SamlFailure::UNUSABLE_SUBJECT, 'The assertion carries no stable subject (subject attribute or non-transient NameID).');
        }

        $email = isset($mapping['email']) ? $this->first($assertion, $mapping['email']) : null;
        if ($email === null && $assertion->nameIdFormat === Constants::NAMEID_EMAIL_ADDRESS) {
            $email = $assertion->nameId;
        }
        if ($email === null || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new SamlFailure(SamlFailure::MISSING_EMAIL, 'The assertion carries no usable e-mail address.');
        }

        $firstName   = isset($mapping['first_name']) ? $this->first($assertion, $mapping['first_name']) : null;
        $lastName    = isset($mapping['last_name']) ? $this->first($assertion, $mapping['last_name']) : null;
        $displayName = isset($mapping['display_name']) ? $this->first($assertion, $mapping['display_name']) : null;
        $joined      = trim(implode(' ', array_filter([$firstName, $lastName], static fn (?string $part): bool => $part !== null)));
        $name        = $displayName ?? ($joined !== '' ? $joined : null);
        $groups      = isset($mapping['groups']) ? array_values(array_filter($assertion->values($mapping['groups']), static fn (string $group): bool => trim($group) !== '')) : [];

        return new SocialUserProfile(
            $subject,
            $email,
            $name,
            ['email' => $email, 'name' => $name, 'first_name' => $firstName, 'last_name' => $lastName, 'groups' => $groups],
            $provider->isEmailVerified() ? true : null,
            $assertion->issuer,
            null,
            [
                'issuer'                   => $assertion->issuer,
                'name_id'                  => $assertion->nameId,
                'name_id_format'           => $assertion->nameIdFormat,
                'session_index'            => $assertion->sessionIndex,
                'authn_context_class_refs' => $assertion->authnContextClassRefs,
                'attributes'               => $assertion->attributes,
                'groups'                   => $groups,
                'assertion_id'             => $assertion->assertionId,
            ],
        );
    }

    private function first(SamlAssertion $assertion, string $name): ?string
    {
        foreach ($assertion->values($name) as $value) {
            $value = trim($value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
