<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\User\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Doctrine\EntityManagerRecovery;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Transaction\AuthKitTransactionRunner;
use Nowo\AuthKitBundle\User\UserWriterInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Default {@see UserWriterInterface} (the behaviour before 1.41): persist / remove + flush on the bundle's entity
 * manager; the password hash is written to the property of the first `registration_fields[]` entry of type `password`
 * (default `password`) and stored by the next flush.
 *
 * 1.50.0 (Beacon spec 239): every write is a unit of work of AuthKit's transaction runner
 * ({@see AuthKitTransactionRunner::runWithAccounts()}): nested in the caller's unit when there is one (registration,
 * invitation acceptance, social sign-up), its own otherwise. A failure leaves no transaction level open and detaches
 * only the rows it touched; a manager closed by a failed flush is replaced at the next boundary.
 */
final readonly class DoctrineUserWriter implements UserWriterInterface
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

    public function add(ProfileSettings $profile, UserInterface $user): void
    {
        $this->write(fn () => $this->entityManager->persist($user));
    }

    public function remove(ProfileSettings $profile, UserInterface $user): void
    {
        $this->write(fn () => $this->entityManager->remove($user));
    }

    public function changePassword(ProfileSettings $profile, PasswordAuthenticatedUserInterface $user, string $hashedPassword): void
    {
        $this->propertyAccessor->setValue($user, $this->passwordProperty($profile), $hashedPassword);
    }

    public function save(ProfileSettings $profile, PasswordAuthenticatedUserInterface|UserInterface $user): void
    {
        $this->write(fn () => $this->entityManager->persist($user));
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
