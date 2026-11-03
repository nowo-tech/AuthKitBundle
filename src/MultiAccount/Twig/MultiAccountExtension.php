<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount\Twig;

use Nowo\AuthKitBundle\Mfa\Security\MfaPendingToken;
use Nowo\AuthKitBundle\MultiAccount\AccountState;
use Nowo\AuthKitBundle\MultiAccount\AccountVault;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `auth_kit_accounts(profile = null, display = 'full')` (1.34, registered with `multi_account.enabled`): the
 * {@see AccountSwitcherView} of the current browser for the switcher partial `@NowoAuthKitBundle/accounts/_switcher
 * .html.twig`, or null without a multi-account profile. `display` = `masked` / `none` for anonymous pages (D4).
 */
final class MultiAccountExtension extends AbstractExtension
{
    public function __construct(
        private readonly AccountVault $vault,
        private readonly ProfileRegistry $profiles,
        private readonly RequestStack $requests,
        private readonly TokenStorageInterface $tokens,
        private readonly CsrfOnlyFormFactory $csrfForms,
        private readonly AuthKitUrlGenerator $urls,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('auth_kit_accounts', $this->switcher(...))];
    }

    public function switcher(?string $profile = null, string $display = 'full'): ?AccountSwitcherView
    {
        $request  = $this->requests->getMainRequest();
        $settings = $this->profileFor($profile);
        if (!$request instanceof Request || !$settings instanceof ProfileSettings) {
            return null;
        }

        return $this->view($request, $settings, $display);
    }

    public function view(Request $request, ProfileSettings $profile, string $display): AccountSwitcherView
    {
        $routes = $profile->multiAccount['routes'];
        $rows   = [];
        $active = null;
        foreach ($this->vault->accounts($request, $profile) as $account) {
            $active = $account->isActive() ? $account->id : $active;
            $rows[] = $this->row($profile, $account, $display);
        }
        $signedIn = $this->tokens->getToken()?->getUser() instanceof UserInterface;

        return new AccountSwitcherView(
            $profile->name,
            $rows,
            $this->vault->canAdd($request, $profile) ? $this->csrfForms->createNamed($this->urls->generate((string) $routes['add']['name']), 'nowo_auth_kit_accounts.add.' . $profile->name)->createView() : null,
            $rows !== [] || $signedIn ? $this->csrfForms->createNamed($this->urls->generate((string) $routes['sign_out_all']['name']), 'nowo_auth_kit_accounts.sign_out_all.' . $profile->name)->createView() : null,
            $this->urls->generate((string) $routes['chooser']['name']),
            ($profile->multiAccount['chooser']['cross_tab_notice'] ?? false) === true,
            $active,
        );
    }

    private function row(ProfileSettings $profile, AccountState $account, string $display): AccountRow
    {
        $routes = $profile->multiAccount['routes'];

        return new AccountRow(
            $account->id,
            $account->presentation->nameFor($display),
            $account->presentation->identifierFor($display),
            $account->presentation->avatarUrl,
            $account->presentation->initials(),
            $account->state,
            $account->isActive() ? null : $this->csrfForms->createNamed($this->urls->generate((string) $routes['switch']['name'], ['account' => $account->id]), 'nowo_auth_kit_accounts.switch.' . $profile->name . '.' . $account->id)->createView(),
            $this->csrfForms->createNamed($this->urls->generate((string) $routes['sign_out']['name'], ['account' => $account->id]), 'nowo_auth_kit_accounts.sign_out.' . $profile->name . '.' . $account->id)->createView(),
        );
    }

    private function profileFor(?string $name): ?ProfileSettings
    {
        if ($name !== null) {
            return $this->vault->profile($name);
        }
        $token = $this->tokens->getToken();
        $user  = $token instanceof MfaPendingToken ? null : $token?->getUser();
        $own   = $user instanceof UserInterface ? $this->profiles->resolveForObject($user) : $this->profiles->getDefault();

        return $own?->multiAccountEnabled() === true ? $own : null;
    }
}
