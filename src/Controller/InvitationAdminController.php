<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use DateTimeImmutable;
use LogicException;
use Nowo\AuthKitBundle\DependencyInjection\InvitationsConfiguration;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Flash\AuthKitFlashMessenger;
use Nowo\AuthKitBundle\Form\FormCsrf;
use Nowo\AuthKitBundle\Form\InvitationFilterType;
use Nowo\AuthKitBundle\Form\InvitationFormType;
use Nowo\AuthKitBundle\Invitation\InvitationManager;
use Nowo\AuthKitBundle\Invitation\InvitationOutcome;
use Nowo\AuthKitBundle\Invitation\InvitationRequest;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Nowo\AuthKitBundle\Invitation\Security\InvitationsAccessGuard;
use Nowo\AuthKitBundle\Invitation\Ui\InvitationAdminPages;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Repository\InvitationRepository;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\UiKitBundle\View\Pagination;
use Psr\Clock\ClockInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;

use function array_filter;
use function array_map;
use function array_values;
use function ceil;
use function in_array;
use function is_array;
use function is_string;
use function max;
use function min;
use function str_starts_with;

/**
 * Invitation administration of a profile (`invitations.mode: enabled`): list, invite, resend, revoke. Routes come only
 * from the `nowo_auth_kit` route loader (`invitations.routes`, REQ-SF-004 rule 7).
 *
 * Every action checks {@see InvitationsAccessGuard::canManage()} first (anonymous or refused: AccessDeniedException,
 * so the firewall sends anonymous visitors to the sign-in page and refuses the others with 403). Every POST also needs
 * a valid CSRF token bound to the action and the target (fail closed: no CSRF extension, or an invalid token → 403).
 * Responses are `private, no-store`; redirects are path-only (never built from the Host header).
 */
final class InvitationAdminController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly RequestProfileResolver $profileResolver,
        private readonly InvitationsAccessGuard $guard,
        private readonly InvitationManager $manager,
        private readonly InvitationRepository $invitations,
        private readonly InvitationAdminPages $pages,
        private readonly CsrfOnlyFormFactory $csrfForms,
        private readonly AuthKitUrlGenerator $urls,
        private readonly AuthKitFlashMessenger $flashMessenger,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(path: '/admin/invitations', name: 'nowo_auth_kit_invitations', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $profile = $this->profile($request);
        $routes  = InvitationAdminPages::routes($profile);
        $filters = $this->formFactory->create(InvitationFilterType::class, ['status' => 'open'], [
            'action' => $this->urls->generate($routes['index']['name']),
        ]);
        $filters->handleRequest($request);
        /** @var array{q?: ?string, status?: ?string} $data */
        $data   = $filters->isSubmitted() && $filters->isValid() ? (array) $filters->getData() : [];
        $status = is_string($data['status'] ?? null) && in_array($data['status'], InvitationRepository::FILTERS, true) ? $data['status'] : 'open';
        $query  = is_string($data['q'] ?? null) ? $data['q'] : '';

        $now        = DateTimeImmutable::createFromInterface($this->clock->now());
        $total      = $this->invitations->countForListing($profile->name, $status, $query, $now);
        $perPage    = $request->query->getInt('per_page', (int) ($profile->invitations['admin']['per_page'] ?? 25));
        $perPage    = in_array($perPage, InvitationsConfiguration::PER_PAGE_CHOICES, true) ? $perPage : (int) ($profile->invitations['admin']['per_page'] ?? 25);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = max(1, min($totalPages, $request->query->getInt('page', 1)));
        $rows       = $this->invitations->findForListing($profile->name, $status, $query, $now, $perPage, ($page - 1) * $perPage);
        $pagination = new Pagination(
            $page,
            $perPage,
            $total,
            $totalPages,
            $routes['index']['name'],
            array_filter(['q' => $query, 'status' => $status === 'open' ? '' : $status], static fn (string $value): bool => $value !== ''),
            allowedPerPage: InvitationsConfiguration::PER_PAGE_CHOICES,
        );

        return $this->render($profile, 'index', [
            'page' => $this->pages->indexPage(
                $profile,
                array_map(static fn (Invitation $invitation): InvitationSnapshot => InvitationSnapshot::of($invitation, $now), $rows),
                $filters->createView(),
                $pagination,
                $this->urls->generate($routes['index']['name']),
                // SEC-004 CD M1: Resend / Revoke only on rows whose every role the administrator holds.
                fn (InvitationSnapshot $invitation): bool => $this->guard->holdsAll($this->guard->user(), $invitation->roles),
            ),
        ]);
    }

    #[Route(path: '/admin/invitations/new', name: 'nowo_auth_kit_invitations_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $profile  = $this->profile($request);
        $settings = $profile->invitations;
        $form     = $this->formFactory->create(InvitationFormType::class, ['ttlDays' => (int) ($settings['ttl_days'] ?? 7)], [
            'action'      => $this->urls->generate(InvitationAdminPages::routes($profile)['new']['name']),
            'roles'       => $this->guard->grantableRoles(array_values(array_filter(is_array($settings['roles'] ?? null) ? $settings['roles'] : [], is_string(...)))),
            'ttl_choices' => InvitationsConfiguration::ttlChoices($settings),
            'profile'     => $profile->name,
        ]);

        if ($request->isMethod('POST')) {
            $this->assertCsrfAvailable($form);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                /** @var array{email: string, roles?: list<string>, ttlDays?: int, message?: ?string} $data */
                $data    = $form->getData();
                $outcome = $this->manager->invite($this->guard->user(), $profile->name, new InvitationRequest(
                    $data['email'],
                    $data['roles'] ?? [],
                    $data['ttlDays'] ?? null,
                    $data['message'] ?? null,
                    $request->getLocale(),
                ));

                return $this->outcome($request, $profile, $outcome, 'invitation.flash.sent');
            }
        }

        return $this->render($profile, 'new', [
            'page' => $this->pages->newPage($profile, $form->createView()),
        ], $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    #[Route(path: '/admin/invitations/{id}/resend', name: 'nowo_auth_kit_invitations_resend', requirements: ['id' => Invitation::PUBLIC_ID_REQUIREMENT], methods: ['POST'])]
    public function resend(Request $request, string $id): Response
    {
        $profile = $this->profile($request);
        $this->requireCsrf($request, InvitationAdminPages::resendTokenId($profile->name, $id));

        return $this->outcome($request, $profile, $this->manager->resend($this->guard->user(), $this->invitation($profile, $id)), 'invitation.flash.resent');
    }

    #[Route(path: '/admin/invitations/{id}/revoke', name: 'nowo_auth_kit_invitations_revoke', requirements: ['id' => Invitation::PUBLIC_ID_REQUIREMENT], methods: ['POST'])]
    public function revoke(Request $request, string $id): Response
    {
        $profile = $this->profile($request);
        $this->requireCsrf($request, InvitationAdminPages::revokeTokenId($profile->name, $id));

        return $this->outcome($request, $profile, $this->manager->revoke($this->guard->user(), $this->invitation($profile, $id)), 'invitation.flash.revoked');
    }

    /**
     * Profile of the route, with invitations enabled and an administrator the guard accepts.
     */
    private function profile(Request $request): ProfileSettings
    {
        $profile = $this->profileResolver->resolve($request);
        if (!$profile->invitationsEnabled()) {
            throw new NotFoundHttpException('Invitations are disabled.');
        }
        if (!$this->guard->canManage($profile->name)) {
            throw new AccessDeniedException('Access denied to the invitations.');
        }

        return $profile;
    }

    private function invitation(ProfileSettings $profile, string $id): Invitation
    {
        return $this->invitations->findOneByPublicId($profile->name, $id) ?? throw new NotFoundHttpException('Unknown invitation.');
    }

    /**
     * SEC-004 P2: a form without CSRF protection never mutates anything (fails closed).
     *
     * @param FormInterface<mixed> $form
     */
    private function assertCsrfAvailable(FormInterface $form): void
    {
        if (!FormCsrf::isProtected($form)) {
            throw new AccessDeniedHttpException('CSRF protection is not available.');
        }
    }

    /**
     * The FormKit CSRF-only form of the action must be submitted with a valid token (fail closed: 403).
     */
    private function requireCsrf(Request $request, string $tokenId): void
    {
        $form = $this->csrfForms->createNamed($request->getPathInfo(), $tokenId);
        $this->assertCsrfAvailable($form);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    private function outcome(Request $request, ProfileSettings $profile, InvitationOutcome $outcome, string $successKey): Response
    {
        $email = $outcome->invitation->email ?? '';
        if ($outcome->status === InvitationOutcome::LINK && $outcome->invitation instanceof InvitationSnapshot && is_string($outcome->url)) {
            return $this->render($profile, 'link', ['page' => $this->pages->linkPage($profile, $outcome->invitation, $outcome->url)]);
        }

        match ($outcome->status) {
            InvitationOutcome::SENT, InvitationOutcome::REVOKED => $this->flashMessenger->flash($request, 'success', $successKey, ['%email%' => $email]),
            InvitationOutcome::NOT_DELIVERED                    => $this->flashMessenger->flash($request, 'warning', 'invitation.flash.not_delivered', ['%email%' => $email]),
            InvitationOutcome::RATE_LIMITED                     => $this->flashMessenger->flash($request, 'error', 'invitation.flash.rate_limited'),
            InvitationOutcome::NOT_ALLOWED                      => $this->flashMessenger->flash($request, 'error', 'invitation.flash.not_allowed'),
            InvitationOutcome::CLOSED                           => $this->flashMessenger->flash($request, 'error', 'invitation.flash.closed'),
            InvitationOutcome::NOT_CONFIGURED                   => $this->flashMessenger->flash($request, 'error', 'invitation.flash.not_configured'),
            default                                             => $this->flashMessenger->flash($request, 'error', 'invitation.flash.not_open'),
        };

        return $this->backToIndex($profile);
    }

    private function backToIndex(ProfileSettings $profile): Response
    {
        return $this->noStore(new Response('', Response::HTTP_SEE_OTHER, [
            'Location' => self::pathOnly($this->urls->generate(InvitationAdminPages::routes($profile)['index']['name'])),
        ]));
    }

    /**
     * Redirect targets are paths of this application only: a value that is not a single-slash path (`//host`,
     * `/\host`, absolute URL) is a configuration error.
     */
    public static function pathOnly(string $path): string
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_starts_with($path, '/\\')) {
            throw new LogicException('AuthKit invitation redirects must be application paths.');
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(ProfileSettings $profile, string $page, array $context, int $status = Response::HTTP_OK): Response
    {
        return $this->noStore(new Response($this->twig->render(InvitationAdminPages::template($profile, $page), $context), $status));
    }

    private function noStore(Response $response): Response
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
