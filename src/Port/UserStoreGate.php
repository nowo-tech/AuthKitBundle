<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port;

use Nowo\AuthKitBundle\DependencyInjection\Compiler\AuthKitPortsPass;
use Nowo\AuthKitBundle\Port\Internal\UserWriterInterface;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\User\Doctrine\DoctrineUserWriter;
use Nowo\AuthKitBundle\User\UserStoreInterface;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * AuthKit's internal {@see UserWriterInterface} over the {@see UserStoreInterface} port (1.46.0; 2.0: always, see
 * services.yaml and {@see AuthKitPortsPass}): each user object becomes an {@see AuthUserRef} carrying it. The built-in
 * {@see DoctrineUserWriter} gets {@see AuthUserRefs::carrying()} (it reads nothing but the object, and a new account may
 * have no id yet), any other store the full reference ({@see AuthUserRefs::fromUser()}), as in 1.x. Exceptions (a
 * duplicate) propagate.
 *
 * @internal
 */
final readonly class UserStoreGate implements UserWriterInterface
{
    public function __construct(private UserStoreInterface $store)
    {
    }

    /**
     * Whether the writer stores through AuthKit's Doctrine default on the application's manager (the account write is
     * then flushed inside AuthKit's unit of work, as with the 1.x `instanceof DoctrineUserWriter` checks); false for any
     * other store, a decorator of the default included.
     */
    public static function storesThroughDoctrine(UserWriterInterface $writer): bool
    {
        return $writer instanceof self && $writer->store instanceof DoctrineUserWriter;
    }

    public function add(ProfileSettings $profile, UserInterface $user): void
    {
        // @igor-ignore - Stateless user port call (stores the host user); no service state changes.
        $this->store->add($profile, $this->ref($user));
    }

    public function remove(ProfileSettings $profile, UserInterface $user): void
    {
        // @igor-ignore - Stateless user port call (stores the host user); no service state changes.
        $this->store->remove($profile, $this->ref($user));
    }

    public function changePassword(ProfileSettings $profile, PasswordAuthenticatedUserInterface $user, string $hashedPassword): void
    {
        // @igor-ignore - Stateless user port call (stores the host user); no service state changes.
        $this->store->changePassword($profile, $this->ref($user), $hashedPassword);
    }

    public function save(ProfileSettings $profile, PasswordAuthenticatedUserInterface|UserInterface $user): void
    {
        // @igor-ignore - Stateless user port call (stores the host user); no service state changes.
        $this->store->save($profile, $this->ref($user));
    }

    private function ref(object $user): AuthUserRef
    {
        return $this->store instanceof DoctrineUserWriter ? AuthUserRefs::carrying($user) : AuthUserRefs::fromUser($user);
    }
}
