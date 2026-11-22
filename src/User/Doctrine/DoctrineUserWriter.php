<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\User\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\AuthKitBundle\Doctrine\EntityManagerRecovery;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Transaction\AuthKitTransactionRunner;
use Nowo\AuthKitBundle\User\UserStoreInterface;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

use function get_debug_type;
use function is_object;
use function sprintf;

/**
 * Default {@see UserStoreInterface} (the behaviour before 1.41; 2.0: on the port, it implemented the removed
 * UserWriterInterface): persist / remove + flush on the bundle's entity
 * manager; the password hash is written to the property of the first `registration_fields[]` entry of type `password`
 * (default `password`) and stored by the next flush.
 *
 * 1.50.0 (Beacon spec 239): every write is a unit of work of AuthKit's transaction runner
 * ({@see AuthKitTransactionRunner::runWithAccounts()}): nested in the caller's unit when there is one (registration,
 * invitation acceptance, social sign-up), its own otherwise. A failure leaves no transaction level open and detaches
 * only the rows it touched; a manager closed by a failed flush is replaced at the next boundary.
 */
final readonly class DoctrineUserWriter implements UserStoreInterface
{
    // 1.50.0: the EntityManagerRecovery argument is kept for compatibility (closed managers are replaced at the boundary).
    // @phpstan-ignore constructor.unusedParameter
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PropertyAccessorInterface $propertyAccessor,
        EntityManagerRecovery $entityManagerRecovery = new EntityManagerRecovery(),
        private ?AuthKitTransactionRunner $transactions = null,
    ) {
    }

    public function add(ProfileSettings $profile, AuthUserRef $user): void
    {
        $object = $this->object($user, 'add');
        $this->write(fn () => $this->entityManager->persist($object));
    }

    public function remove(ProfileSettings $profile, AuthUserRef $user): void
    {
        $object = $this->object($user, 'remove');
        $this->write(fn () => $this->entityManager->remove($object));
    }

    public function changePassword(ProfileSettings $profile, AuthUserRef $user, string $hashedPassword): void
    {
        $object = $this->object($user, 'changePassword');
        if (!$object instanceof PasswordAuthenticatedUserInterface) {
            throw new LogicException(sprintf('%s::changePassword() needs a reference to a %s object, got %s.', self::class, PasswordAuthenticatedUserInterface::class, get_debug_type($object)));
        }
        $this->propertyAccessor->setValue($object, $this->passwordProperty($profile), $hashedPassword);
    }

    public function save(ProfileSettings $profile, AuthUserRef $user): void
    {
        $object = $this->object($user, 'save');
        $this->write(fn () => $this->entityManager->persist($object));
    }

    /**
     * The user entity a reference carries (AuthKit always hands the object; a reference by id alone cannot be stored).
     *
     * @throws LogicException when the reference carries no object
     */
    private function object(AuthUserRef $user, string $method): object
    {
        $object = $user->user();
        if (!is_object($object)) {
            throw new LogicException(sprintf('%s::%s() needs a reference that carries the user entity (AuthUserRef::of()).', self::class, $method));
        }

        return $object;
    }

    /**
     * Runs the change in a unit of work that flushes the application's manager inside the transaction.
     *
     * @param callable(): mixed $change
     */
    private function write(callable $change): void
    {
        $this->transactions()->runWithAccounts(function () use ($change): void {
            $change();
            // The accounts' manager of the injected runner is the application's; a writer built on another manager
            // flushes its own here (a no-op when it is the same one).
            $this->entityManager->flush();
        });
    }

    private function transactions(): AuthKitTransactionRunner
    {
        return $this->transactions ?? new AuthKitTransactionRunner($this->entityManager);
    }

    private function passwordProperty(ProfileSettings $profile): string
    {
        foreach ($profile->registrationFields as $field) {
            if ($field['type'] === 'password') {
                return $field['property'];
            }
        }

        return 'password';
    }
}
