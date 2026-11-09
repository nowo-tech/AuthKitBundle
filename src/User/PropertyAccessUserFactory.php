<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\User;

use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_string;
use function method_exists;

/**
 * Default {@see UserFactoryInterface} (the behaviour before 1.41): `new ($profile->userClass)()` and PropertyAccessor
 * writes.
 *
 * - form / invitation: every mapped `registration_fields[]` value to its `property`, `hash: true` values hashed;
 * - admin: the identifier to `user_identifier_field`, the hashed password to the property of the first hashed
 *   registration field (default `password`);
 * - social: the e-mail to `user_identifier_field`, the name to `displayName` (else `name`) when writable, the hashed
 *   random password to `password`;
 * - roles with `setRoles()`, else a writable `roles` property.
 */
final readonly class PropertyAccessUserFactory implements UserFactoryInterface
{
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher,
        private PropertyAccessorInterface $propertyAccessor,
    ) {
    }

    public function create(ProfileSettings $profile, NewUser $newUser): UserInterface&PasswordAuthenticatedUserInterface
    {
        /** @var PasswordAuthenticatedUserInterface&UserInterface $user */
        $user = new ($profile->userClass)();

        match ($newUser->source) {
            UserCreationSource::Form, UserCreationSource::Invitation => $this->applyFormData($profile, $user, $newUser->formData),
            UserCreationSource::Admin                                => $this->applyAdmin($profile, $user, $newUser),
            UserCreationSource::Social                               => $this->applySocial($profile, $user, $newUser),
        };

        if (method_exists($user, 'setRoles')) {
            $user->setRoles($newUser->roles);
        } elseif ($this->propertyAccessor->isWritable($user, 'roles')) {
            $this->propertyAccessor->setValue($user, 'roles', $newUser->roles);
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $formData
     */
    private function applyFormData(ProfileSettings $profile, PasswordAuthenticatedUserInterface&UserInterface $user, array $formData): void
    {
        foreach ($profile->registrationFields as $field) {
            if (($field['mapped'] ?? true) === false) {
                continue;
            }

            $value = $formData[$field['name']] ?? null;

            if ($field['hash'] && is_string($value)) {
                $value = $this->passwordHasher->hashPassword($user, $value);
            }

            if ($value !== null) {
                $this->propertyAccessor->setValue($user, $field['property'], $value);
            }
        }
    }

    private function applyAdmin(ProfileSettings $profile, PasswordAuthenticatedUserInterface&UserInterface $user, NewUser $newUser): void
    {
        $this->propertyAccessor->setValue($user, $profile->userIdentifierField, $newUser->identifier);
        $this->propertyAccessor->setValue(
            $user,
            $this->hashedFieldProperty($profile),
            $this->passwordHasher->hashPassword($user, (string) $newUser->plainPassword),
        );
    }

    private function applySocial(ProfileSettings $profile, PasswordAuthenticatedUserInterface&UserInterface $user, NewUser $newUser): void
    {
        $this->propertyAccessor->setValue($user, $profile->userIdentifierField, $newUser->identifier);

        if ($newUser->displayName !== null && $this->propertyAccessor->isWritable($user, 'displayName')) {
            $this->propertyAccessor->setValue($user, 'displayName', $newUser->displayName);
        } elseif ($newUser->displayName !== null && $this->propertyAccessor->isWritable($user, 'name')) {
            $this->propertyAccessor->setValue($user, 'name', $newUser->displayName);
        }

        $this->propertyAccessor->setValue($user, 'password', $this->passwordHasher->hashPassword($user, (string) $newUser->plainPassword));
    }

    private function hashedFieldProperty(ProfileSettings $profile): string
    {
        foreach ($profile->registrationFields as $field) {
            if (($field['hash'] ?? false) === true && is_string($field['property'] ?? null) && $field['property'] !== '') {
                return $field['property'];
            }
        }

        return 'password';
    }
}
