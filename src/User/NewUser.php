<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\User;

use Nowo\AuthKitBundle\Identity\UserIdentifierNormalizerInterface;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use SensitiveParameter;

use function array_fill_keys;
use function array_keys;
use function is_string;

/**
 * What AuthKit asks a {@see UserFactoryInterface} to build (1.41). Plain data only: the factory decides how the host
 * user is constructed (named constructor, domain invariants, ...).
 *
 * - {@see UserCreationSource::Form} / {@see UserCreationSource::Invitation}: `$formData` holds the submitted registration
 *   form, keyed by `registration_fields[].name` (fields with `hash: true` are still plain: hash them in the factory).
 * - {@see UserCreationSource::Admin}: `$identifier` + `$plainPassword`.
 * - {@see UserCreationSource::Social}: `$identifier` (the provider e-mail), `$displayName` and a random `$plainPassword`
 *   (the account signs in through the provider).
 *
 * 1.49.0 (audit OV-L1): AuthKit hands the factory / {@see UserCreatorInterface} the identifier in the form of the
 * configured {@see UserIdentifierNormalizerInterface} ({@see self::normalizedWith()}): `$identifier`, or the
 * `$formData` value of the registration field mapped to `user_identifier_field`. Hosts with immutable users or their
 * own aggregate store it as received.
 *
 * Holds plain passwords: never log or serialise it. `var_dump()` / the Symfony dumper show the form keys and the
 * password masked ({@see self::__debugInfo()}).
 */
final readonly class NewUser
{
    /**
     * @param array<string, mixed> $formData
     * @param list<string> $roles
     */
    public function __construct(
        public UserCreationSource $source,
        public array $roles,
        #[SensitiveParameter]
        public array $formData = [],
        public ?string $identifier = null,
        #[SensitiveParameter]
        public ?string $plainPassword = null,
        public ?string $displayName = null,
    ) {
    }

    /**
     * @param array<string, mixed> $formData
     * @param list<string> $roles
     */
    public static function fromForm(#[SensitiveParameter] array $formData, array $roles, UserCreationSource $source = UserCreationSource::Form): self
    {
        return new self($source, $roles, $formData);
    }

    /**
     * @param list<string> $roles
     */
    public static function forAdmin(string $identifier, #[SensitiveParameter] string $plainPassword, array $roles): self
    {
        return new self(UserCreationSource::Admin, $roles, [], $identifier, $plainPassword);
    }

    /**
     * @param list<string> $roles
     */
    public static function forSocial(string $identifier, ?string $displayName, #[SensitiveParameter] string $plainPassword, array $roles): self
    {
        return new self(UserCreationSource::Social, $roles, [], $identifier, $plainPassword, $displayName);
    }

    /**
     * 1.49.0: the identifier of the account to create: `$identifier` (admin, social), else the `$formData` value of the
     * registration field mapped to the profile's `user_identifier_field` (form, invitation). Null when there is none
     * (the host derives the identifier from other fields).
     */
    public function identifierValue(ProfileSettings $profile): ?string
    {
        if ($this->source === UserCreationSource::Admin || $this->source === UserCreationSource::Social) {
            return $this->identifier;
        }
        $field = self::identifierFormField($profile);
        $value = $field !== null ? ($this->formData[$field] ?? null) : null;

        return is_string($value) ? $value : null;
    }

    /**
     * 1.49.0 (audit OV-L1): a copy whose identifier ({@see self::identifierValue()}) is in the normaliser's form. The
     * same object when there is no identifier, the normaliser refuses it (the caller refuses the creation) or keeps it.
     */
    public function normalizedWith(ProfileSettings $profile, UserIdentifierNormalizerInterface $normalizer): self
    {
        $value      = $this->identifierValue($profile);
        $normalized = $value !== null ? $normalizer->normalize($value) : null;
        if ($normalized === null || $normalized === $value) {
            return $this;
        }
        if ($this->source === UserCreationSource::Admin || $this->source === UserCreationSource::Social) {
            return new self($this->source, $this->roles, $this->formData, $normalized, $this->plainPassword, $this->displayName);
        }
        $formData                                               = $this->formData;
        $formData[(string) self::identifierFormField($profile)] = $normalized;

        return new self($this->source, $this->roles, $formData, $this->identifier, $this->plainPassword, $this->displayName);
    }

    /** The mapped, non-password registration field whose property is the profile's `user_identifier_field`. */
    private static function identifierFormField(ProfileSettings $profile): ?string
    {
        foreach ($profile->registrationFields as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '' || ($field['type'] ?? 'text') === 'password' || ($field['mapped'] ?? true) === false) {
                continue;
            }
            if ((string) ($field['property'] ?? $name) === $profile->userIdentifierField) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'source'        => $this->source,
            'roles'         => $this->roles,
            'formData'      => array_fill_keys(array_keys($this->formData), '***'),
            'identifier'    => $this->identifier,
            'plainPassword' => $this->plainPassword === null ? null : '***',
            'displayName'   => $this->displayName,
        ];
    }
}
