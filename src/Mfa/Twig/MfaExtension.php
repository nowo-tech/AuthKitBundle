<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\Twig;

use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Mfa\MfaStatus;
use Nowo\AuthKitBundle\Mfa\Security\MfaPendingToken;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig functions of the second factor (1.34, registered with `mfa.enabled`):
 *
 * - `auth_kit_mfa_status(user = null)`: {@see MfaStatus} of the user (default: the signed-in one), or null;
 * - `auth_kit_mfa_notice()`: the status when the signed-in user is required to enrol and still in the grace period
 *   (for a reminder banner), else null;
 * - `auth_kit_mfa_account_path()`: path of the account page of the signed-in user's profile, or null.
 *
 * The partial `@NowoAuthKitBundle/mfa/_account.html.twig` renders the status card of a host account page.
 */
final class MfaExtension extends AbstractExtension
{
    public function __construct(
        private readonly MfaManager $manager,
        private readonly TokenStorageInterface $tokens,
        private readonly AuthKitUrlGenerator $urls,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('auth_kit_mfa_status', $this->status(...)),
            new TwigFunction('auth_kit_mfa_notice', $this->notice(...)),
            new TwigFunction('auth_kit_mfa_account_path', $this->accountPath(...)),
        ];
    }

    public function status(?UserInterface $user = null): ?MfaStatus
    {
        $user ??= $this->currentUser();

        return $user instanceof UserInterface ? $this->manager->status($user) : null;
    }

    public function notice(): ?MfaStatus
    {
        $status = $this->status();

        return $status?->inGrace() === true ? $status : null;
    }

    public function accountPath(): ?string
    {
        $user    = $this->currentUser();
        $profile = $user instanceof UserInterface ? $this->manager->profileFor($user) : null;

        return $profile instanceof ProfileSettings ? $this->urls->generate((string) $profile->mfa['routes']['account']['name']) : null;
    }

    private function currentUser(): ?UserInterface
    {
        $token = $this->tokens->getToken();

        return $token instanceof MfaPendingToken ? null : $token?->getUser();
    }
}
