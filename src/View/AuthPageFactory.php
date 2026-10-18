<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\View;

use InvalidArgumentException;
use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\UiKitBundle\View\AuthPage;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\Link;
use Stringable;
use Symfony\Component\Form\FormView;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

use function array_keys;
use function implode;
use function is_bool;
use function is_scalar;
use function sprintf;

/**
 * Builds the UiKit {@see AuthPage} view models of the auth pages (REQ-UI-003 page contract): labels as
 * translation keys of the bundle domain, the per-profile layout override and the bundle name for the
 * `theme.bundles.NowoAuthKitBundle` exception. Plain data, no services, no request state (worker safe).
 */
final class AuthPageFactory
{
    public const BUNDLE = 'NowoAuthKitBundle';

    /**
     * Page => [panel heading (AuthPage::title), document title, intro, submit label].
     *
     * @var array<string, array{0: string, 1: string, 2: ?string, 3: ?string}>
     */
    private const PAGES = [
        'login'               => ['login.heading', 'login.title', null, 'login.submit'],
        'register'            => ['register.heading', 'register.title', null, 'register.submit'],
        'reset_request'       => ['reset.request.heading', 'reset.request.title', 'reset.request.help', 'reset.request.submit'],
        'reset_password'      => ['reset.password.heading', 'reset.password.title', null, 'reset.password.submit'],
        'reset_password_code' => ['reset.code.heading', 'reset.code.title', 'reset.code.help', 'reset.code.submit'],
        'magic_login_request' => ['magic_login.request.heading', 'magic_login.request.title', 'magic_login.request.help', 'magic_login.request.submit'],
        'magic_login_confirm' => ['magic_login.confirm.heading', 'magic_login.confirm.title', 'magic_login.confirm.help', 'magic_login.confirm.submit'],
        'qr_login_show'       => ['qr_login.show.heading', 'qr_login.show.title', 'qr_login.show.intro', 'qr_login.show.retry'],
        'qr_login_approve'    => ['qr_login.approve.heading', 'qr_login.approve.title', 'qr_login.approve.warning', 'qr_login.approve.submit'],
    ];

    /**
     * @param iterable<Link> $links footer links
     * @param array<string, bool|float|int|string|null> $attributes extra attributes of the scaffold root
     */
    public function create(
        string $page,
        ProfileSettings $profile,
        ?FormView $form = null,
        iterable $links = [],
        Label|string|null $error = null,
        array $attributes = [],
    ): AuthPage {
        [$title, , $intro, $submit] = $this->definition($page);
        $brand                      = $profile->webUi['brand'] ?? null;

        return new AuthPage(
            title: self::label($title),
            form: $form,
            intro: $intro !== null ? self::label($intro) : null,
            brand: $brand !== null && $brand !== '' ? $brand : null,
            error: $error,
            submitLabel: $submit !== null ? self::label($submit) : null,
            links: $links,
            bundle: self::BUNDLE,
            layout: $profile->layoutTemplate(),
            attributes: ['data-testid' => 'auth-kit-' . $page, 'data-nowo-auth-kit-page' => $page] + $attributes,
        );
    }

    /**
     * Template context of an auth page: the view model plus the variables shared by every page (document title,
     * form themes, the 1.x `layout_template` for host templates written against 1.x).
     *
     * @param array<string, mixed> $variables page-specific (1.x) variables
     *
     * @return array<string, mixed>
     */
    public function context(string $page, AuthPage $authPage, ProfileSettings $profile, array $variables = []): array
    {
        return [
            'page'            => $authPage,
            'auth_kit_page'   => $page,
            'document_title'  => self::label($this->definition($page)[1]),
            'form_themes'     => $profile->formThemes(),
            'layout_template' => $profile->legacyLayoutTemplate(),
        ] + $variables;
    }

    /**
     * @param array<string, bool|float|int|string|null> $attributes
     */
    public function link(string $labelKey, string $href, array $attributes = []): Link
    {
        return new Link(self::label($labelKey), $href, $attributes);
    }

    /**
     * Security error of the last login attempt as a label of the `security` domain (as Symfony's login templates).
     */
    public function authenticationError(?AuthenticationException $exception): ?Label
    {
        if (!$exception instanceof AuthenticationException) {
            return null;
        }

        $params = [];
        foreach ($exception->getMessageData() as $name => $value) {
            if (is_scalar($value) || $value === null || $value instanceof Stringable) {
                $params[(string) $name] = is_bool($value) ? (int) $value : $value;
            }
        }

        return new Label($exception->getMessageKey(), 'security', $params);
    }

    public static function label(string $key): Label
    {
        return new Label($key, NowoAuthKitBundle::TRANSLATION_DOMAIN);
    }

    /**
     * @return list<string>
     */
    public static function pages(): array
    {
        return array_keys(self::PAGES);
    }

    /**
     * @return array{0: string, 1: string, 2: ?string, 3: ?string}
     */
    private function definition(string $page): array
    {
        return self::PAGES[$page] ?? throw new InvalidArgumentException(sprintf('Unknown AuthKit page "%s" (known: %s).', $page, implode(', ', array_keys(self::PAGES))));
    }
}
