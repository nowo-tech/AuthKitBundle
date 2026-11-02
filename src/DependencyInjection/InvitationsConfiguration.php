<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

use function array_filter;
use function array_values;
use function is_array;
use function is_int;
use function is_string;
use function ksort;
use function preg_match;
use function str_starts_with;
use function trim;

/**
 * `profiles.<name>.invitations` (1.32): invitations by e-mail instead of (or besides) the open registration. Off by
 * default (`mode: disabled`); every key has a safe default (REQ-SF-006).
 */
final class InvitationsConfiguration
{
    public const MODE_DISABLED = 'disabled';

    public const MODE_ENABLED = 'enabled';

    public const TTL_MIN = 1;

    public const TTL_MAX = 90;

    /** Page sizes of the administration list (the `per_page` query parameter is one of these). */
    public const PER_PAGE_CHOICES = [10, 25, 50, 100];

    /** Route key => [default path, default name] of `invitations.routes`. */
    public const ROUTES = [
        'accept' => ['/invitation/{token}', 'nowo_auth_kit_invitation_accept'],
        'index'  => ['/admin/invitations', 'nowo_auth_kit_invitations'],
        'new'    => ['/admin/invitations/new', 'nowo_auth_kit_invitations_new'],
        'resend' => ['/admin/invitations/{id}/resend', 'nowo_auth_kit_invitations_resend'],
        'revoke' => ['/admin/invitations/{id}/revoke', 'nowo_auth_kit_invitations_revoke'],
    ];

    /** Administration page => default template (`invitations.admin.templates.<page>`). */
    public const ADMIN_TEMPLATES = [
        'index' => '@NowoAuthKitBundle/invitations/index.html.twig',
        'new'   => '@NowoAuthKitBundle/invitations/new.html.twig',
        'link'  => '@NowoAuthKitBundle/invitations/link.html.twig',
    ];

    /** `invitations.payload_merge`: re-inviting replaces top-level payload keys (1.32 behaviour). */
    public const PAYLOAD_MERGE_REPLACE = 'replace';

    /** `invitations.payload_merge`: maps merge recursively, lists are unioned (1.33). */
    public const PAYLOAD_MERGE_DEEP = 'deep';

    /** Route keys of the administration pages (`invitations.admin_routes: false` leaves them unregistered, 1.33). */
    public const ADMIN_ROUTE_KEYS = ['index', 'new', 'resend', 'revoke'];

    public const MAIL_HTML_TEMPLATE = '@NowoAuthKitBundle/email/invitation.html.twig';

    public const MAIL_TEXT_TEMPLATE = '@NowoAuthKitBundle/email/invitation.txt.twig';

    public static function createNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('invitations'))->getRootNode();
        $node
            ->info('Invitations by e-mail (1.32). disabled by default; with registration_mode: invite_only they are the only way to create an account.')
            ->addDefaultsIfNotSet()
            ->children()
                ->enumNode('mode')
                    ->values([self::MODE_DISABLED, self::MODE_ENABLED])
                    ->info('disabled: no invitation entity, route or page. enabled: the administration pages and the accept page (routes under invitations.routes).')
                    ->defaultValue(self::MODE_DISABLED)
                ->end()
                ->integerNode('ttl_days')
                    ->info('Default validity of a link in days (resend restarts it from now).')
                    ->defaultValue(7)
                    ->min(self::TTL_MIN)
                    ->max(self::TTL_MAX)
                ->end()
                ->arrayNode('ttl_choices')
                    ->info('Validities (days) offered in the invite form; ttl_days is always among them.')
                    ->integerPrototype()->min(self::TTL_MIN)->max(self::TTL_MAX)->end()
                    ->defaultValue([1, 3, 7, 14, 30])
                ->end()
                ->booleanNode('lock_email')
                    ->info('true: the invited e-mail is pre-filled and read-only on the accept page (enforced server-side). false: pre-filled, the invitee may register another address.')
                    ->defaultTrue()
                ->end()
                ->scalarNode('email_field')
                    ->info('Registration field that receives the invited e-mail. null: the field whose property is user_identifier_field, else the field named "email".')
                    ->defaultNull()
                ->end()
                ->arrayNode('roles')
                    ->info('Roles an inviter may grant (each also needs the inviter to hold it). Empty: invitees get registration_role only. Re-checked on acceptance.')
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(static fn (mixed $role): bool => !is_string($role) || preg_match('/^ROLE_[A-Z0-9_]+$/', $role) !== 1)
                            ->thenInvalid('Invalid invitations.roles entry %s: use ROLE_* names.')
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
                ->scalarNode('registration_role')
                    ->info('Base role of an account created from an invitation (1.33), instead of the profile registration_role (e.g. when registration_role is ROLE_ADMIN for the first user). null: registration_role.')
                    ->defaultNull()
                    ->validate()
                        ->ifTrue(static fn (mixed $role): bool => $role !== null && (!is_string($role) || preg_match('/^ROLE_[A-Z0-9_]+$/', $role) !== 1))
                        ->thenInvalid('Invalid invitations.registration_role %s: use a ROLE_* name or null.')
                    ->end()
                ->end()
                ->scalarNode('merge_key')
                    ->info('Payload key that, with the e-mail, identifies the open invitation a re-invite updates (1.33), e.g. "organization": one open invitation per (e-mail, payload[merge_key]). null: one open invitation per e-mail.')
                    ->defaultNull()
                    ->validate()
                        ->ifTrue(static fn (mixed $key): bool => $key !== null && (!is_string($key) || preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $key) !== 1))
                        ->thenInvalid('Invalid invitations.merge_key %s: 1-64 characters among letters, digits, "_", "-" and ".", or null.')
                    ->end()
                ->end()
                ->enumNode('payload_merge')
                    ->values([self::PAYLOAD_MERGE_REPLACE, self::PAYLOAD_MERGE_DEEP])
                    ->info('How a re-invite merges its payload into the open invitation (1.33). replace: top-level keys replaced (1.32). deep: maps merged recursively, lists unioned.')
                    ->defaultValue(self::PAYLOAD_MERGE_REPLACE)
                ->end()
                ->booleanNode('admin_routes')
                    ->info('false: the administration routes (index, new, resend, revoke) are not registered, for hosts with their own invitation UI (1.33). The accept route stays.')
                    ->defaultTrue()
                ->end()
                ->booleanNode('show_link_when_undelivered')
                    ->info('When the e-mail cannot be sent (no mailer, transport error), show the link once to the inviter (no-store page). false: report "not sent" and keep the link secret.')
                    ->defaultFalse()
                ->end()
                ->integerNode('purge_after_days')
                    ->info('nowo:auth-kit:invitations:purge deletes revoked and expired invitations older than this.')
                    ->defaultValue(90)
                    ->min(1)
                    ->max(3650)
                ->end()
                ->integerNode('invite_rate_limit')
                    ->info('Invitations created or resent per inviter and invite_rate_window.')
                    ->defaultValue(20)
                    ->min(1)
                ->end()
                ->integerNode('invite_rate_window')
                    ->defaultValue(3600)
                    ->min(60)
                ->end()
                ->integerNode('resend_rate_limit')
                    ->info('Sends of one invitation per resend_rate_window (re-inviting the same e-mail counts as a resend).')
                    ->defaultValue(3)
                    ->min(1)
                ->end()
                ->integerNode('resend_rate_window')
                    ->defaultValue(3600)
                    ->min(60)
                ->end()
                ->integerNode('accept_rate_limit')
                    ->info('Accept page hits (opening a link, submitting the form) per client IP and accept_rate_window.')
                    ->defaultValue(10)
                    ->min(1)
                ->end()
                ->integerNode('accept_rate_window')
                    ->defaultValue(900)
                    ->min(60)
                ->end()
                ->arrayNode('mail')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('from')
                            ->info('Sender address. null: the mailer default (framework.mailer.headers / envelope).')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('html_template')->defaultValue(self::MAIL_HTML_TEMPLATE)->cannotBeEmpty()->end()
                        ->scalarNode('text_template')->defaultValue(self::MAIL_TEXT_TEMPLATE)->cannotBeEmpty()->end()
                    ->end()
                ->end()
                ->arrayNode('admin')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('per_page')
                            ->values(self::PER_PAGE_CHOICES)
                            ->defaultValue(25)
                        ->end()
                        ->scalarNode('layout_template')
                            ->info('Layout of the administration pages (UiKit page contract). null: nowo_ui_kit.layouts.admin.')
                            ->defaultNull()
                        ->end()
                        ->arrayNode('templates')
                            ->info('Template overrides of the administration pages (index, new, link).')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('index')->defaultValue(self::ADMIN_TEMPLATES['index'])->cannotBeEmpty()->end()
                                ->scalarNode('new')->defaultValue(self::ADMIN_TEMPLATES['new'])->cannotBeEmpty()->end()
                                ->scalarNode('link')->defaultValue(self::ADMIN_TEMPLATES['link'])->cannotBeEmpty()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->append(self::createSecurityNode())
                ->append(self::createRoutesNode())
            ->end();

        return $node;
    }

    /**
     * REQ-UI-002 canonical keys of the administration pages.
     */
    private static function createSecurityNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('security'))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('access_roles')
                    ->info('The administrator must be granted at least one of these roles (empty is refused: it would let every signed-in user invite).')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue(['ROLE_ADMIN'])
                    ->validate()
                        ->ifTrue(static fn (mixed $roles): bool => !is_array($roles) || array_values(array_filter($roles, static fn (mixed $role): bool => is_string($role) && trim($role) !== '')) === [])
                        ->thenInvalid('invitations.security.access_roles must list at least one role (an empty list would let every signed-in user invite); use access_checker for custom rules.')
                    ->end()
                ->end()
                ->scalarNode('access_checker')
                    ->info('Service id implementing InvitationsAccessCheckerInterface (null: the built-in role checker on access_roles).')
                    ->defaultNull()
                ->end()
                ->booleanNode('allow_unauthenticated')
                    ->info('DEV/DEMO only, a literal boolean: open the administration pages without sign-in. Refused unless kernel.debug is true.')
                    ->defaultFalse()
                ->end()
            ->end();

        return $node;
    }

    private static function createRoutesNode(): ArrayNodeDefinition
    {
        $node     = (new TreeBuilder('routes'))->getRootNode();
        $children = $node
            ->info('Paths and names of the invitation routes (registered only with mode: enabled; route names must be unique across profiles).')
            ->addDefaultsIfNotSet()
            ->children();
        foreach (self::ROUTES as $key => [$path, $name]) {
            $children->arrayNode($key)
                ->addDefaultsIfNotSet()
                ->children()
                    ->scalarNode('path')
                        ->defaultValue($path)
                        ->validate()
                            ->ifTrue(static fn (mixed $value): bool => !is_string($value) || !str_starts_with($value, '/') || str_starts_with($value, '//') || str_starts_with($value, '/\\'))
                            ->thenInvalid('Invalid invitation route path %s: it must start with a single "/".')
                        ->end()
                    ->end()
                    ->scalarNode('name')->defaultValue($name)->cannotBeEmpty()->end()
                ->end()
            ->end();
        }
        $children->end();

        return $node;
    }

    /**
     * The invite form validities: ttl_choices plus ttl_days, sorted, unique.
     *
     * @param array<string, mixed> $invitations
     *
     * @return list<int>
     */
    public static function ttlChoices(array $invitations): array
    {
        $days = [];
        foreach ([...(is_array($invitations['ttl_choices'] ?? null) ? $invitations['ttl_choices'] : []), $invitations['ttl_days'] ?? 7] as $value) {
            if (is_int($value)) {
                $days[$value] = $value;
            }
        }
        ksort($days);

        return array_values($days);
    }
}
