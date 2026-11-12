<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support\Ports;

use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Tests\Stub\HostDomainUser;
use Nowo\AuthKitBundle\User\DuplicateUserException;
use Nowo\AuthKitBundle\User\NewUser;
use Nowo\AuthKitBundle\User\UserCreationSource;
use Nowo\AuthKitBundle\User\UserCreatorInterface;
use Nowo\AuthKitBundle\User\UserDirectoryInterface;
use Nowo\AuthKitBundle\User\UserStoreInterface;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

use function count;
use function is_string;
use function mb_strtolower;
use function sprintf;

/**
 * A DDD host's user adapter on the framework-free 1.46 ports only (no deprecated interface, no Doctrine): creator,
 * store and directory over an in-memory repository of {@see HostDomainUser}, plus the firewall's user provider.
 * Passwords use the `plaintext` hasher of the test kernel.
 *
 * @implements UserProviderInterface<HostDomainUser>
 */
final class InMemoryTwinUsers implements UserCreatorInterface, UserStoreInterface, UserDirectoryInterface, UserProviderInterface
{
    /** @var array<string, HostDomainUser> keyed by uuid */
    public array $users = [];

    /** @var list<string> port calls, e.g. "add ada@example.com" */
    public array $calls = [];

    private int $sequence = 0;

    public function create(ProfileSettings $profile, NewUser $newUser): AuthUserRef
    {
        $email = $newUser->source === UserCreationSource::Form ? $newUser->formData['email'] ?? null : $newUser->identifier;
        $plain = $newUser->formData['password'] ?? $newUser->plainPassword;
        $user  = HostDomainUser::register(sprintf('uuid-%d', ++$this->sequence), is_string($email) ? $email : '', is_string($plain) ? $plain : '', $newUser->roles);

        return $this->ref($user);
    }

    public function add(ProfileSettings $profile, AuthUserRef $user): void
    {
        $this->calls[] = 'add ' . $user->identifier;
        foreach ($this->users as $stored) {
            if ($stored->getUserIdentifier() === $user->identifier) {
                throw new DuplicateUserException('taken');
            }
        }
        $object = $user->user();
        if ($object instanceof HostDomainUser) {
            $this->users[$object->getId()] = $object;
        }
    }

    public function remove(ProfileSettings $profile, AuthUserRef $user): void
    {
        $this->calls[] = 'remove ' . $user->identifier;
        unset($this->users[(string) $user->id]);
    }

    public function changePassword(ProfileSettings $profile, AuthUserRef $user, string $hashedPassword): void
    {
        $this->calls[] = 'changePassword ' . $user->identifier;
        $object = $user->user();
        if ($object instanceof HostDomainUser) {
            $object->changePassword($hashedPassword);
        }
    }

    public function save(ProfileSettings $profile, AuthUserRef $user): void
    {
        $this->calls[] = 'save ' . $user->identifier;
    }

    public function findByIdentifier(ProfileSettings $profile, string $identifier): ?AuthUserRef
    {
        foreach ($this->users as $user) {
            if (mb_strtolower($user->getUserIdentifier()) === mb_strtolower($identifier)) {
                return $this->ref($user);
            }
        }

        return null;
    }

    public function findById(ProfileSettings $profile, string $id): ?AuthUserRef
    {
        return isset($this->users[$id]) ? $this->ref($this->users[$id]) : null;
    }

    public function countUsers(ProfileSettings $profile): int
    {
        return count($this->users);
    }

    public function hasCaseVariant(ProfileSettings $profile, string $property, string $value): bool
    {
        return false;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === HostDomainUser::class;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        foreach ($this->users as $user) {
            if ($user->getUserIdentifier() === $identifier) {
                return $user;
            }
        }

        throw new UserNotFoundException();
    }

    private function ref(HostDomainUser $user): AuthUserRef
    {
        return AuthUserRef::of($user, HostDomainUser::class, $user->getId(), $user->getUserIdentifier(), $user->getRoles());
    }
}
