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
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `auth_kit_accounts(profile = null, display = null)` (1.34, registered with `multi_account.enabled`): the
 * {@see AccountSwitcherView} of the current browser for the switcher partial `@NowoAuthKitBundle/accounts/_switcher
 * .html.twig`, or null without a multi-account profile. `display` (`full`, `masked`, `none`) defaults to `full` when
 * signed in and to `chooser.identifier_display` otherwise (D4, DE-L1).
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
        private readonly FormFactoryInterface $forms,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('auth_kit_accounts', $this->switcher(...)),
            new TwigFunction('auth_kit_accounts_target_path_field', $this->targetPathField(...)),
        ];
    }

    /**
     * The hidden `_target_path` of a switch form (REQ-TWIG-005: rendered through a form widget). The controller checks
     * the value; here it is only escaped by the form theme. The id carries the account, one per row. A form of a single
     * non-compound field gets no CSRF token (GY-I11): rendering this must not write to the session, and it also works
     * without the form CSRF extension, where a `csrf_protection` option would be refused.
     */
    public function targetPathField(string $path, string $account): FormView
    {
        $view             = $this->forms->createNamed('_target_path', HiddenType::class, $path)->createView();
        $view->vars['id'] = '_target_path_' . $account;

        return $view;
    }

    public function switcher(?string $profile = null, ?string $display = null): ?AccountSwitcherView
    {
        $request  = $this->requests->getMainRequest();
        $settings = $this->profileFor($profile);
        if (!$request instanceof Request || !$settings instanceof ProfileSettings) {
            return null;
        }
        // Full identifiers only for a signed-in browser; anonymous pages follow `chooser.identifier_display` (DE-L1).
        $display ??= $this->vault->isSignedIn() ? 'full' : (string) ($settings->multiAccount['chooser']['identifier_display'] ?? 'masked');

        return $this->view($request, $settings, $display);
    }

    public function view(Request $request, ProfileSettings $profile, string $display): AccountSwitcherView
    {
        $routes = $profile->multiAccount['routes'];
        $rows   = [];
        $active = null;
        // 1.35 (audit EC-L1): the other accounts' pictures only for a signed-in browser seeing full identifiers, never
        // on an anonymous page (sign-in page, chooser after sign-out), where the visitor may be someone else.
        $otherAvatars = ($profile->multiAccount['chooser']['other_account_avatars'] ?? false) === true && $display === 'full' && $this->vault->isSignedIn();
        foreach ($this->vault->accounts($request, $profile) as $account) {
            $active = $account->isActive() ? $account->id : $active;
            $rows[] = $this->row($profile, $account, $display, $otherAvatars);
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

    private function row(ProfileSettings $profile, AccountState $account, string $display, bool $otherAvatars): AccountRow
    {
        $routes = $profile->multiAccount['routes'];

        return new AccountRow(
            $account->id,
            $account->presentation->nameFor($display),
            $account->presentation->identifierFor($display),
            $account->presentation->avatarUrlFor($account->isActive(), $otherAvatars),
            $account->presentation->initialsFor($display),
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
