<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Flash\AuthKitFlashMessenger;
use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Mfa\MfaStatus;
use Nowo\AuthKitBundle\Mfa\Security\MfaPendingToken;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;

use function is_array;
use function is_string;

/**
 * Optional administrator reset page (1.34, `mfa.admin.routes: true`; D-M10). Shows whether a user has a second
 * factor (never the secret) and clears it on a CSRF-protected POST ({@see MfaManager::reset()}: audited, every
 * session of the user signed out, re-enrolment). Requires one of `mfa.admin.access_roles`; refused while impersonating
 * and for one's own account (use the account page). Users are looked up by their identifier field.
 */
final class MfaAdminController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly CsrfOnlyFormFactory $csrfForms,
        private readonly TokenStorageInterface $tokens,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly MfaManager $manager,
        private readonly RequestProfileResolver $profileResolver,
        private readonly ManagerRegistry $doctrine,
        private readonly AuthKitUrlGenerator $urls,
        private readonly AuthKitFlashMessenger $flash,
    ) {
    }

    #[Route(path: '/admin/mfa/{user}', name: 'nowo_auth_kit_mfa_admin_reset', requirements: ['user' => '[^/]{1,180}'], methods: ['GET', 'POST'])]
    public function reset(Request $request, string $user): Response
    {
        $profile = $this->profileResolver->resolve($request);
        $actor   = $this->actor($profile);
        $target  = $this->target($profile, $user);
        if ($target->getUserIdentifier() === $actor->getUserIdentifier()) {
            throw new AccessDeniedHttpException('Use the account page for your own second factor.');
        }
        foreach ($target->getRoles() as $role) {
            if (!$this->authorization->isGranted($role)) {
                // DD-I2: never reset the factor of an account holding a role the administrator does not hold.
                throw new AccessDeniedHttpException('The account holds a role you do not hold.');
            }
        }
        $url  = $this->urls->generate((string) $profile->mfa['routes']['admin_reset']['name'], ['user' => $target->getUserIdentifier()]);
        $form = $this->csrfForms->createNamed($url, 'nowo_auth_kit_mfa_admin_reset.' . $profile->name . '.' . $target->getUserIdentifier());
        if ($request->isMethod('POST')) {
            $form->handleRequest($request);
            if (!$form->isSubmitted() || !$form->isValid()) {
                throw new AccessDeniedHttpException('Invalid CSRF token.');
            }
            $done = $this->manager->reset($target, $actor);
            $this->flash->flash($request, $done ? 'success' : 'warning', $done ? 'mfa.admin.flash.reset' : 'mfa.admin.flash.not_enabled', ['%user%' => $target->getUserIdentifier()]);

            return new RedirectResponse($url, Response::HTTP_SEE_OTHER);
        }
        $status = $this->manager->status($target);

        return MfaChallengeController::noStore(new Response($this->twig->render('@NowoAuthKitBundle/mfa/admin_reset.html.twig', [
            'mfa_user'       => $target->getUserIdentifier(),
            'mfa_status'     => $status,
            'mfa_reset_form' => $form->createView(),
        ])));
    }

    private function actor(ProfileSettings $profile): UserInterface
    {
        $token = $this->tokens->getToken();
        $actor = $token?->getUser();
        if (!$actor instanceof UserInterface || $token instanceof MfaPendingToken) {
            throw new AccessDeniedException('Sign in as an administrator.');
        }
        if ($token instanceof SwitchUserToken) {
            throw new AccessDeniedHttpException('Second factors cannot be reset while impersonating.');
        }
        $roles = is_array($profile->mfa['admin']['access_roles'] ?? null) ? $profile->mfa['admin']['access_roles'] : [];
        foreach ($roles as $role) {
            if (is_string($role) && $this->authorization->isGranted($role)) {
                return $actor;
            }
        }

        throw new AccessDeniedHttpException('Not allowed to reset second factors.');
    }

    private function target(ProfileSettings $profile, string $identifier): UserInterface
    {
        $user = $this->doctrine->getRepository($profile->userClass)->findOneBy([$profile->userIdentifierField => $identifier]);
        if (!$user instanceof UserInterface || !$this->manager->status($user) instanceof MfaStatus) {
            throw new NotFoundHttpException('Unknown user.');
        }

        return $user;
    }
}
