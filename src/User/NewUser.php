<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\User;

use SensitiveParameter;

/**
 * What AuthKit asks a {@see UserFactoryInterface} to build (1.41). Plain data only: the factory decides how the host
 * user is constructed (named constructor, domain invariants, ...).
 *
 * - {@see UserCreationSource::Form} / {@see UserCreationSource::Invitation}: `$formData` holds the submitted registration
 *   form, keyed by `registration_fields[].name` (fields with `hash: true` are still plain: hash them in the factory).
 * - {@see UserCreationSource::Admin}: `$identifier` + `$plainPassword`.
 * - {@see UserCreationSource::Social}: `$identifier` (the provider e-mail), `$displayName` and a random `$plainPassword`
 *   (the account signs in through the provider).
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
    public static function fromForm(array $formData, array $roles, UserCreationSource $source = UserCreationSource::Form): self
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
}
