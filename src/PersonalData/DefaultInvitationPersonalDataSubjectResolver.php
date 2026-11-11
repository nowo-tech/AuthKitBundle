<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PersonalData;

use InvalidArgumentException;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_values;
use function filter_var;
use function is_string;

use const FILTER_VALIDATE_EMAIL;

/**
 * Default invitation subjects of a user (1.45):
 *
 * - user identifiers: the identifier captured when the erasure started and the current `getUserIdentifier()`;
 * - e-mails: the user's value of the profile's invitation e-mail field (`invitations.email_field`, else the field of
 *   `user_identifier_field`, else `email`), the `email` property, and each identifier above that is an e-mail address.
 *
 * Values that cannot be a subject (empty, NUL byte, too long) are skipped. Stateless (worker mode).
 */
final class DefaultInvitationPersonalDataSubjectResolver implements InvitationPersonalDataSubjectResolverInterface
{
    private readonly PropertyAccessorInterface $propertyAccessor;

    public function __construct(
        private readonly ProfileRegistry $profiles,
        ?PropertyAccessorInterface $propertyAccessor = null,
    ) {
        $this->propertyAccessor = $propertyAccessor ?? PropertyAccess::createPropertyAccessor();
    }

    public function subjectsFor(object $user, ?string $originalIdentifier): array
    {
        $identifiers = [$originalIdentifier];
        if ($user instanceof UserInterface) {
            $identifiers[] = $user->getUserIdentifier();
        }
        $emails = [$this->read($user, $this->emailProperty($user)), $this->read($user, 'email')];
        foreach ($identifiers as $identifier) {
            if (is_string($identifier) && filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[] = $identifier;
            }
        }
        $subjects = [];
        foreach ($identifiers as $identifier) {
            self::add($subjects, $identifier, false);
        }
        foreach ($emails as $email) {
            self::add($subjects, $email, true);
        }

        return array_values($subjects);
    }

    /**
     * Adds a subject unless the value cannot be one (shared with the UserKit eraser for `context['previous_email']`).
     *
     * @param array<string, InvitationPersonalDataSubject> $subjects
     */
    public static function add(array &$subjects, mixed $value, bool $email): void
    {
        if (!is_string($value) || $value === '') {
            return;
        }
        try {
            $subject = $email ? InvitationPersonalDataSubject::fromEmail($value) : InvitationPersonalDataSubject::fromUserIdentifier($value);
        } catch (InvalidArgumentException) {
            return;
        }
        $subjects[$subject->key()] = $subject;
    }

    /** The user property behind the profile's invitation e-mail field, if any. */
    private function emailProperty(object $user): ?string
    {
        $profile = $this->profiles->resolveForObject($user);
        $field   = $profile instanceof ProfileSettings ? $profile->invitationEmailField() : null;
        if (!$profile instanceof ProfileSettings || $field === null) {
            return null;
        }
        $property = $field;
        foreach ($profile->registrationFields as $definition) {
            if (($definition['name'] ?? null) === $field) {
                $declared = $definition['property'] ?? $field;
                $property = is_string($declared) && $declared !== '' ? $declared : $field;
                break;
            }
        }

        return $property;
    }

    private function read(object $user, ?string $property): ?string
    {
        if ($property === null || !$this->propertyAccessor->isReadable($user, $property)) {
            return null;
        }
        $value = $this->propertyAccessor->getValue($user, $property);

        return is_string($value) ? $value : null;
    }
}
