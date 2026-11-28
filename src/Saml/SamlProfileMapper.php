<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Saml;

use Nowo\AuthKitBundle\Entity\SamlProvider;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Security\ExactIdentifier;
use Nowo\AuthKitBundle\SocialLogin\SocialUserProfile;
use OneLogin\Saml2\Constants;

use function array_filter;
use function array_key_first;
use function array_values;
use function count;
use function filter_var;
use function implode;
use function strlen;
use function trim;

use const FILTER_VALIDATE_EMAIL;

/**
 * Turns a checked assertion into the {@see SocialUserProfile} of the social pipeline (2.10.0) with the provider row's
 * attribute mapping:
 *
 * - subject: the `subject` attribute (one value), else the NameID unless it is transient (a transient NameID changes
 *   at every sign-in and would link a new identity each time), of another format than the row's `name_id_format`, or
 *   not persistent with `require_persistent_subject`; must pass {@see ExactIdentifier} (≤ 255 bytes);
 * - e-mail: the `email` attribute (one value), else an `emailAddress` NameID; required; verified only when the row says
 *   the IdP is authoritative for it (`email_verified`);
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
        $subject = isset($mapping['subject']) ? $this->single($assertion, $mapping['subject'], SamlFailure::UNUSABLE_SUBJECT) : $this->nameIdSubject($provider, $assertion);
        if ($subject === null || !ExactIdentifier::usable($subject, SocialLoginAccount::PROVIDER_USER_ID_MAX_LENGTH)) {
            throw new SamlFailure(SamlFailure::UNUSABLE_SUBJECT, 'The assertion carries no stable subject (subject attribute or non-transient NameID).');
        }

        $email = isset($mapping['email']) ? $this->single($assertion, $mapping['email'], SamlFailure::MISSING_EMAIL) : null;
        if (!isset($mapping['email']) && $assertion->nameIdFormat === Constants::NAMEID_EMAIL_ADDRESS) {
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

    /**
     * The NameID as subject: never a transient one; with a requested `name_id_format`, only a NameID of that format;
     * with `require_persistent_subject`, only a persistent one (an e-mail or unspecified NameID is never then a subject).
     */
    private function nameIdSubject(SamlProvider $provider, SamlAssertion $assertion): ?string
    {
        $format = $assertion->nameIdFormat;
        if ($format === Constants::NAMEID_TRANSIENT
            || ($provider->getNameIdFormat() !== null && $format !== $provider->getNameIdFormat())
            || ($provider->isRequirePersistentSubject() && $format !== Constants::NAMEID_PERSISTENT)) {
            return null;
        }

        return $assertion->nameId;
    }

    /**
     * The one value of an identity attribute (subject, e-mail): null without one; several distinct values are refused
     * (which one would identify the user is not ours to guess).
     *
     * @throws SamlFailure with $reason
     */
    private function single(SamlAssertion $assertion, string $name, string $reason): ?string
    {
        $values = [];
        foreach ($assertion->values($name) as $value) {
            $value = trim($value);
            if ($value !== '') {
                $values[$value] = true;
            }
        }
        if (count($values) > 1) {
            throw new SamlFailure($reason, 'An identity attribute of the assertion has several values.');
        }

        $value = array_key_first($values);

        return $value === null ? null : (string) $value;
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
