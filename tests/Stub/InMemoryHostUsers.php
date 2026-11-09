<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Stub;

use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\User\DuplicateUserException;
use Nowo\AuthKitBundle\User\NewUser;
use Nowo\AuthKitBundle\User\UserCreationSource;
use Nowo\AuthKitBundle\User\UserFactoryInterface;
use Nowo\AuthKitBundle\User\UserLookupInterface;
use Nowo\AuthKitBundle\User\UserWriterInterface;
use RuntimeException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function count;
use function is_string;
use function mb_strtolower;
use function sprintf;

/**
 * A host adapter for the 1.41 user ports without Doctrine: factory, writer and lookup over an in-memory repository of
 * {@see HostDomainUser}.
 */
final class InMemoryHostUsers implements UserFactoryInterface, UserWriterInterface, UserLookupInterface
{
    /** @var array<string, HostDomainUser> keyed by uuid */
    public array $users = [];

    /** @var list<string> */
    public array $calls = [];

    /** remove() throws (compensation failure). */
    public bool $failRemove = false;

    /** Writer that only stores on save() (aggregate + repository shape): changePassword() keeps the hash pending. */
    public bool $deferUntilSave = false;

    /** @var array<string, string> pending password hashes keyed by uuid */
    private array $pendingPasswords = [];

    private int $sequence = 0;

    public function create(ProfileSettings $profile, NewUser $newUser): UserInterface&PasswordAuthenticatedUserInterface
    {
        $email = $newUser->source === UserCreationSource::Form || $newUser->source === UserCreationSource::Invitation
            ? $newUser->formData['email'] ?? null
            : $newUser->identifier;
        $plain = $newUser->formData['password'] ?? $newUser->plainPassword;

        return HostDomainUser::register(
            sprintf('uuid-%d', ++$this->sequence),
            is_string($email) ? $email : '',
            'host-hash:' . (is_string($plain) ? $plain : ''),
            $newUser->roles,
        );
    }

    public function add(ProfileSettings $profile, UserInterface $user): void
    {
        $this->calls[] = 'add';
        foreach ($this->users as $stored) {
            if ($stored->getUserIdentifier() === $user->getUserIdentifier()) {
                throw new DuplicateUserException('taken');
            }
        }
        if ($user instanceof HostDomainUser) {
            $this->users[$user->getId()] = $user;
        }
    }

    public function remove(ProfileSettings $profile, UserInterface $user): void
    {
        $this->calls[] = 'remove';
        if ($this->failRemove) {
            throw new RuntimeException('remove failed');
        }
        if ($user instanceof HostDomainUser) {
            unset($this->users[$user->getId()]);
        }
    }

    public function changePassword(ProfileSettings $profile, PasswordAuthenticatedUserInterface $user, string $hashedPassword): void
    {
        $this->calls[] = 'changePassword';
        if (!$user instanceof HostDomainUser) {
            return;
        }
        if ($this->deferUntilSave) {
            $this->pendingPasswords[$user->getId()] = $hashedPassword;

            return;
        }
        $user->changePassword($hashedPassword);
    }

    public function save(ProfileSettings $profile, PasswordAuthenticatedUserInterface|UserInterface $user): void
    {
        $this->calls[] = 'save';
        if ($user instanceof HostDomainUser && isset($this->pendingPasswords[$user->getId()])) {
            $user->changePassword($this->pendingPasswords[$user->getId()]);
            unset($this->pendingPasswords[$user->getId()]);
        }
    }

    public function findByIdentifier(ProfileSettings $profile, string $identifier): ?UserInterface
    {
        foreach ($this->users as $user) {
            if ($user->getUserIdentifier() === $identifier) {
                return $user;
            }
        }

        return null;
    }

    public function findById(ProfileSettings $profile, string $id): ?UserInterface
    {
        return $this->users[$id] ?? null;
    }

    public function countUsers(ProfileSettings $profile): int
    {
        return count($this->users);
    }

    public function hasCaseVariant(ProfileSettings $profile, string $property, string $value): bool
    {
        foreach ($this->users as $user) {
            if (mb_strtolower($user->getUserIdentifier()) === mb_strtolower($value)) {
                return true;
            }
        }

        return false;
    }
}
