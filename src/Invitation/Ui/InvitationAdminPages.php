<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation\Ui;

use Closure;
use Nowo\AuthKitBundle\DependencyInjection\InvitationsConfiguration;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\Crumb;
use Nowo\UiKitBundle\View\DetailItem;
use Nowo\UiKitBundle\View\DetailSection;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\Pagination;
use Nowo\UiKitBundle\View\ShowPage;
use Symfony\Component\Form\FormView;

use function array_map;
use function is_string;

/**
 * UiKit page contract (REQ-UI-003) of the invitation administration: templates (`invitations.admin.templates`),
 * layout (`invitations.admin.layout_template`, null = `nowo_ui_kit.layouts.admin`) and the {@see ListPage} /
 * {@see FormPage} / {@see ShowPage} view models. Resend and revoke are FormKit CSRF-only forms (REQ-TWIG-005 rule 11)
 * behind the UiKit confirm dialog, each with a token id bound to the action and the row
 * (`<action>/<profile>/<public id>`). Plain data in, immutable view models out (worker safe).
 */
final readonly class InvitationAdminPages
{
    public const BUNDLE = NowoAuthKitBundle::TRANSLATION_DOMAIN;

    public const CSRF_RESEND = 'nowo_auth_kit_invitation_resend';

    public const CSRF_REVOKE = 'nowo_auth_kit_invitation_revoke';

    public function __construct(
        private AuthKitUrlGenerator $urls,
        private CsrfOnlyFormFactory $csrfForms,
    ) {
    }

    /** CSRF token id of "Resend" on one invitation. */
    public static function resendTokenId(string $profile, string $publicId): string
    {
        return self::CSRF_RESEND . '/' . $profile . '/' . $publicId;
    }

    /** CSRF token id of "Revoke" on one invitation. */
    public static function revokeTokenId(string $profile, string $publicId): string
    {
        return self::CSRF_REVOKE . '/' . $profile . '/' . $publicId;
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function label(string $key, array $params = []): Label
    {
        return new Label($key, NowoAuthKitBundle::TRANSLATION_DOMAIN, $params);
    }

    public static function template(ProfileSettings $profile, string $page): string
    {
        $template = $profile->invitations['admin']['templates'][$page] ?? null;

        return is_string($template) && $template !== '' ? $template : InvitationsConfiguration::ADMIN_TEMPLATES[$page];
    }

    /**
     * @return list<Column>
     */
    public static function columns(): array
    {
        return [
            new Column('email', self::label('invitation.column.email'), required: true),
            new Column('status', self::label('invitation.column.status'), required: true),
            new Column('roles', self::label('invitation.column.roles'), priority: 2),
            new Column('invitedBy', self::label('invitation.column.invited_by'), priority: 2),
            new Column('sentAt', self::label('invitation.column.sent_at'), priority: 3),
            new Column('sendCount', self::label('invitation.column.send_count'), priority: 3),
            new Column('expiresAt', self::label('invitation.column.expires_at')),
        ];
    }

    /**
     * The list of a profile: Invite in the header, Resend / Revoke on open rows, bounded pagination.
     *
     * @param list<InvitationSnapshot> $invitations
     */
    public function indexPage(ProfileSettings $profile, array $invitations, FormView $filters, Pagination $pagination, string $clearHref): ListPage
    {
        $routes     = self::routes($profile);
        $rows       = array_map(static fn (InvitationSnapshot $i): InvitationRow => InvitationRow::of($i), $invitations);
        $rowActions = [];
        foreach ($rows as $row) {
            $rowActions[$row->publicId] = $row->status->isOpen() ? [
                $this->post(
                    $routes['resend']['name'],
                    $row->publicId,
                    self::resendTokenId($profile->name, $row->publicId),
                    'custom',
                    'invitation.action.resend',
                    new Confirm(self::label('invitation.confirm.resend_title'), self::label('invitation.confirm.resend', ['%email%' => $row->email]), self::label('invitation.action.resend'), 'primary'),
                    'nowo-auth-kit-invitation-resend',
                ),
                $this->post(
                    $routes['revoke']['name'],
                    $row->publicId,
                    self::revokeTokenId($profile->name, $row->publicId),
                    'delete',
                    'invitation.action.revoke',
                    new Confirm(self::label('invitation.confirm.revoke_title'), self::label('invitation.confirm.revoke', ['%email%' => $row->email]), self::label('invitation.action.revoke')),
                    'nowo-auth-kit-invitation-revoke',
                ),
            ] : [];
        }
        $invite = new Action('create', self::label('invitation.action.invite'), $this->urls->generate($routes['new']['name']), attributes: ['data-testid' => 'nowo-auth-kit-invitation-invite']);

        return new ListPage(
            new PageHeader(self::label('invitation.index.title'), self::label('invitation.index.intro'), [$invite]),
            self::columns(),
            $rows,
            filters: $filters,
            rowActions: $this->perRow($rowActions),
            pagination: $pagination,
            empty: new EmptyState(self::label('invitation.index.empty')),
            filtersClearHref: $clearHref,
            caption: self::label('invitation.index.caption'),
            bundle: self::BUNDLE,
            layout: $this->layout($profile),
            attributes: ['data-testid' => 'nowo-auth-kit-invitations'],
            actionsLabel: self::label('invitation.column.actions'),
        );
    }

    public function newPage(ProfileSettings $profile, FormView $form): FormPage
    {
        $index = $this->urls->generate(self::routes($profile)['index']['name']);
        $title = self::label('invitation.new.title');

        return new FormPage(
            new PageHeader($title, self::label('invitation.new.intro'), [], [new Crumb(self::label('invitation.index.title'), $index), new Crumb($title)]),
            $form,
            submitLabel: self::label('invitation.new.submit'),
            cancelHref: $index,
            cancelLabel: self::label('invitation.new.cancel'),
            bundle: self::BUNDLE,
            layout: $this->layout($profile),
            attributes: ['data-testid' => 'nowo-auth-kit-invitation-new'],
        );
    }

    /**
     * The link shown once (`invitations.show_link_when_undelivered`, the e-mail could not be sent). Rendered with
     * `Cache-Control: no-store`; the link is not clickable.
     */
    public function linkPage(ProfileSettings $profile, InvitationSnapshot $invitation, string $url): ShowPage
    {
        $index = $this->urls->generate(self::routes($profile)['index']['name']);
        $title = self::label('invitation.link.title');

        return new ShowPage(
            new PageHeader($title, self::label('invitation.link.intro', ['%email%' => $invitation->email]), [
                new Action('custom', self::label('invitation.link.back'), $index, attributes: ['data-testid' => 'nowo-auth-kit-invitation-link-back']),
            ], [new Crumb(self::label('invitation.index.title'), $index), new Crumb($title)]),
            [new DetailSection(null, [
                new DetailItem(self::label('invitation.column.email'), $invitation->email),
                new DetailItem(self::label('invitation.column.expires_at'), $invitation->expiresAt->format('Y-m-d H:i T')),
                new DetailItem(self::label('invitation.link.url'), $url),
            ])],
            bundle: self::BUNDLE,
            layout: $this->layout($profile),
            attributes: ['data-testid' => 'nowo-auth-kit-invitation-link'],
        );
    }

    /**
     * @return array<string, array{path: string, name: string}>
     */
    public static function routes(ProfileSettings $profile): array
    {
        /** @var array<string, array{path: string, name: string}> $routes */
        $routes = $profile->invitations['routes'];

        return $routes;
    }

    private function layout(ProfileSettings $profile): ?string
    {
        $layout = $profile->invitations['admin']['layout_template'] ?? null;

        return is_string($layout) && $layout !== '' ? $layout : null;
    }

    /**
     * Row actions built once per page (one CSRF form per action and row).
     *
     * @param array<string, list<Action>> $actions public id => actions
     *
     * @return Closure(InvitationRow): list<Action>
     */
    private function perRow(array $actions): Closure
    {
        return static fn (InvitationRow $row): array => $actions[$row->publicId] ?? [];
    }

    private function post(string $route, string $publicId, string $tokenId, string $kind, string $label, Confirm $confirm, string $testId): Action
    {
        $url = $this->urls->generate($route, ['id' => $publicId]);

        return new Action(
            $kind,
            self::label($label),
            $url,
            method: 'POST',
            confirm: $confirm,
            attributes: ['data-testid' => $testId],
            form: $this->csrfForms->createNamed($url, $tokenId)->createView(),
        );
    }
}
