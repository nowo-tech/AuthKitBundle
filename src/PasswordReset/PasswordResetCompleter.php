<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Doctrine\EntityManagerRecovery;
use Nowo\AuthKitBundle\Port\Internal\UserWriterInterface;
use Nowo\AuthKitBundle\Port\UserStoreGate;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Transaction\AuthKitTransactionRunner;
use Nowo\AuthKitBundle\User\Doctrine\DoctrineUserWriter;
use Nowo\AuthKitBundle\User\Event\CredentialsChangedEvent;
use Nowo\AuthKitBundle\User\UserIds;
use SensitiveParameter;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function trigger_deprecation;

/**
 * Applies a new password and clears the reset credential. The hash is stored through {@see UserWriterInterface}
 * (1.41; default: the password property + flush), then {@see PasswordResetCompletedEvent} is dispatched, and with a
 * non-Doctrine writer {@see CredentialsChangedEvent} (MFA trusted devices forgotten).
 */
final class PasswordResetCompleter
{
    private readonly UserWriterInterface $userWriter;

    private readonly AuthKitTransactionRunner $transactions;

    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        PropertyAccessorInterface $propertyAccessor,
        private readonly PasswordResetTokenManagerInterface $tokenManager,
        private readonly ProfileRegistry $profileRegistry,
        EntityManagerRecovery $entityManagerRecovery = new EntityManagerRecovery(),
        ?UserWriterInterface $userWriter = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        ?AuthKitTransactionRunner $transactions = null,
    ) {
        $this->transactions = $transactions ?? new AuthKitTransactionRunner($entityManager);
        $this->userWriter   = $userWriter ?? new UserStoreGate(new DoctrineUserWriter($entityManager, $propertyAccessor, $entityManagerRecovery, $this->transactions));
    }

    /**
     * @param string|null $credential 2.2.0: the plain link token or code this request validated; with a store that
     *                                implements {@see PasswordResetCredentialClaimInterface} it is claimed once (null:
     *                                the credential is cleared as in 2.1, unguarded)
     *
     * @throws PasswordResetLinkUsedException when another request used up the credential first (2.2.0; nothing written)
     */
    public function complete(PasswordAuthenticatedUserInterface $user, #[SensitiveParameter] string $plainPassword, #[SensitiveParameter] ?string $credential = null): void
    {
        $profile = $this->profileRegistry->resolveForObject($user) ?? $this->profileRegistry->getDefault();
        $hashed  = $this->passwordHasher->hashPassword($user, $plainPassword);

        // 1.50.0 (Beacon spec 239): the new password and the cleared credential are one unit of work: a failure keeps
        // neither (the reset link stays usable), and never stores the password while the credential stays live.
        // 2.2.0 (REQ-RUNTIME-003): the credential is claimed first, once: of two completions of one link or code sent
        // at the same moment, only the one that used it up stores its password; the other one writes nothing.
        if ($credential === null && $this->tokenManager instanceof PasswordResetCredentialClaimInterface) {
            trigger_deprecation('nowo-tech/auth-kit-bundle', '2.2', 'Calling %s::complete() without the validated link token or code ($credential) is deprecated: the credential is cleared without the single-use claim, so two concurrent completions can both store a password. Pass the link token or code your request validated.', self::class);
        }
        $unit = function () use ($profile, $user, $hashed, $credential): void {
            if ($credential !== null && $this->tokenManager instanceof PasswordResetCredentialClaimInterface) {
                if (!$this->tokenManager->claimForUser($user, $credential)) {
                    throw new PasswordResetLinkUsedException();
                }
                $this->userWriter->changePassword($profile, $user, $hashed);
            } else {
                $this->userWriter->changePassword($profile, $user, $hashed);
                // @igor-ignore - Not shared worker service state.
                $this->tokenManager->clearForUser($user);
            }
            $this->userWriter->save($profile, $user);
        };
        UserStoreGate::storesThroughDoctrine($this->userWriter) ? $this->transactions->runWithAccounts($unit) : $this->transactions->runOnConnection($unit);

        $this->eventDispatcher?->dispatch(new PasswordResetCompletedEvent(
            $profile->name,
            $user::class,
            UserIds::of($user),
            $user instanceof UserInterface ? $user->getUserIdentifier() : '',
        ));

        // JW-A4: the Doctrine listeners (MFA trusted devices) see the change of a Doctrine writer; a host writer gets the
        // explicit event, once.
        if ($user instanceof UserInterface && !UserStoreGate::storesThroughDoctrine($this->userWriter)) {
            $this->eventDispatcher?->dispatch(new CredentialsChangedEvent($user));
        }
    }
}
