<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount\Controller;

use Nowo\AuthKitBundle\Flash\AuthKitFlashMessenger;
use Nowo\AuthKitBundle\MultiAccount\AccountRemovalReason;
use Nowo\AuthKitBundle\MultiAccount\AccountVault;
use Nowo\AuthKitBundle\MultiAccount\EventSubscriber\MultiAccountSubscriber;
use Nowo\AuthKitBundle\MultiAccount\SwitchOutcome;
use Nowo\AuthKitBundle\MultiAccount\Twig\MultiAccountExtension;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\View\AuthPageFactory;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;

use function is_string;
use function str_starts_with;

/**
 * The account switcher (1.34, `multi_account.enabled`): chooser page, add another account, switch, sign one account
 * out, sign out of all accounts. Every POST needs the CSRF token of its action and account (403 otherwise); switch and
 * add are rate limited per client IP. Responses are `private, no-store` and never framed; redirects are paths of this
 * application only.
 */
final class AccountsController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly CsrfOnlyFormFactory $csrfForms,
        private readonly AccountVault $vault,
        private readonly MultiAccountExtension $switcher,
        private readonly RequestProfileResolver $profileResolver,
        private readonly AuthPageFactory $pages,
        private readonly AuthKitUrlGenerator $urls,
        private readonly AuthKitFlashMessenger $flash,
        private readonly AuthKitAttemptLimiter $limiter,
        private readonly Security $security,
    ) {
    }

    #[Route(path: '/accounts', name: 'nowo_auth_kit_accounts', methods: ['GET'])]
    public function chooser(Request $request): Response
    {
        $profile = $this->profile($request);
        $signed  = $this->security->getUser() instanceof UserInterface;
        $view    = $this->switcher->view($request, $profile, $signed ? 'full' : (string) ($profile->multiAccount['chooser']['identifier_display'] ?? 'masked'));
        if ($view->isEmpty() && !$signed) {
            return new RedirectResponse($this->urls->generate($profile->routes['login']['name']));
        }
        $page = $this->pages->create('accounts_chooser', $profile, null, [
            $this->pages->link('accounts.chooser.back', $this->afterSwitch($request, $profile)),
        ]);

        return $this->noStore(new Response($this->twig->render($profile->template('accounts_chooser'), $this->pages->context('accounts_chooser', $page, $profile, [
            'accounts_switcher' => $view,
        ]))));
    }

    #[Route(path: '/accounts/add', name: 'nowo_auth_kit_accounts_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        $profile = $this->profile($request);
        $this->requireCsrf($request, 'nowo_auth_kit_accounts.add.' . $profile->name);
        if (!$this->limit($request, $profile, 'add')) {
            return $this->back($request, $profile, 'accounts.flash.rate_limited');
        }
        // @igor-ignore - Stateless service call on the request's own session, not worker state.
        if (!$this->vault->add($request, $profile)) {
            return $this->back($request, $profile, 'accounts.flash.full');
        }

        return $this->noStore(new RedirectResponse($this->urls->generate($profile->routes['login']['name']), Response::HTTP_SEE_OTHER));
    }

    #[Route(path: '/accounts/{account}/switch', name: 'nowo_auth_kit_accounts_switch', requirements: ['account' => '[0-9a-f]{32}'], methods: ['POST'])]
    public function switch(Request $request, string $account): Response
    {
        $profile = $this->profile($request);
        $this->requireCsrf($request, 'nowo_auth_kit_accounts.switch.' . $profile->name . '.' . $account);
        if (!$this->limit($request, $profile, 'switch')) {
            return $this->back($request, $profile, 'accounts.flash.rate_limited');
        }
        $outcome = $this->vault->switch($request, $profile, $account);
        if ($outcome->status === SwitchOutcome::SWITCHED || $outcome->status === SwitchOutcome::REVIVED) {
            if ($outcome->status === SwitchOutcome::SWITCHED) {
                $this->flash->flash($request, 'success', 'accounts.flash.switched', ['%account%' => $outcome->user?->getUserIdentifier() ?? '']);
            }

            return $this->noStore(new RedirectResponse($this->afterSwitch($request, $profile), Response::HTTP_SEE_OTHER));
        }

        return $this->back($request, $profile, (string) $outcome->message, $outcome->parameters);
    }

    #[Route(path: '/accounts/{account}/sign-out', name: 'nowo_auth_kit_accounts_sign_out', requirements: ['account' => '[0-9a-f]{32}'], methods: ['POST'])]
    public function signOut(Request $request, string $account): Response
    {
        $profile = $this->profile($request);
        $this->requireCsrf($request, 'nowo_auth_kit_accounts.sign_out.' . $profile->name . '.' . $account);
        $result = $this->vault->signOut($request, $profile, $account);
        if ($result === false) {
            throw new NotFoundHttpException('Unknown account.');
        }
        if ($result === null) {
            $request->attributes->set(MultiAccountSubscriber::SCOPE_ATTRIBUTE, 'current');

            return $this->noStore($this->security->logout(false) ?? $this->back($request, $profile, null));
        }

        return $this->back($request, $profile, 'accounts.flash.signed_out', [], 'success');
    }

    #[Route(path: '/accounts/sign-out-all', name: 'nowo_auth_kit_accounts_sign_out_all', methods: ['POST'])]
    public function signOutAll(Request $request): Response
    {
        $profile = $this->profile($request);
        $this->requireCsrf($request, 'nowo_auth_kit_accounts.sign_out_all.' . $profile->name);
        $this->vault->endAll($request, $profile, AccountRemovalReason::SignedOutAll);
        if ($this->security->getUser() instanceof UserInterface) {
            $request->attributes->set(MultiAccountSubscriber::SCOPE_ATTRIBUTE, 'current');
            $response = $this->security->logout(false);
            if ($response instanceof Response) {
                return $this->noStore($response);
            }
        }

        return $this->noStore(new RedirectResponse($this->urls->generate($profile->routes['login']['name']), Response::HTTP_SEE_OTHER));
    }

    /**
     * The route's profile (the routes exist only for multi-account profiles).
     */
    private function profile(Request $request): ProfileSettings
    {
        return $this->profileResolver->resolve($request);
    }

    private function requireCsrf(Request $request, string $tokenId): void
    {
        $form = $this->csrfForms->createNamed($request->getPathInfo(), $tokenId);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    private function limit(Request $request, ProfileSettings $profile, string $action): bool
    {
        $limits = $profile->multiAccount['rate_limit'][$action] ?? ['limit' => 30, 'window' => 600];

        return $this->limiter->consume('accounts:' . $action . ':' . ($request->getClientIp() ?? 'unknown'), (int) $limits['limit'], (int) $limits['window']);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function back(Request $request, ProfileSettings $profile, ?string $message, array $parameters = [], string $type = 'error'): Response
    {
        if ($message !== null) {
            $this->flash->flash($request, $type, $message, $parameters);
        }

        return $this->noStore(new RedirectResponse($this->urls->generate((string) $profile->multiAccount['routes']['chooser']['name']), Response::HTTP_SEE_OTHER));
    }

    /**
     * `_target_path` when it is a path of this application, else `multi_account.after_switch`, else the profile's
     * login success route, else `/`.
     */
    private function afterSwitch(Request $request, ProfileSettings $profile): string
    {
        $target = $request->request->get('_target_path');
        if (is_string($target) && str_starts_with($target, '/') && !str_starts_with($target, '//') && !str_starts_with($target, '/\\')) {
            return $target;
        }
        $route = $profile->multiAccount['after_switch'] ?? $profile->loginSuccessRoute;

        return is_string($route) && $route !== '' ? $this->urls->generate($route) : '/';
    }

    private function noStore(Response $response): Response
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
