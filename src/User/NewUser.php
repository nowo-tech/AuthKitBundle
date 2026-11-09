<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\User;

use SensitiveParameter;

use function array_fill_keys;
use function array_keys;

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
