<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Contract;

use Nowo\AuthKitBundle\Mailer\AlwaysOutboundMailReadyChecker;
use Nowo\AuthKitBundle\Tests\Support\AuthPageFixtures;
use Nowo\AuthKitBundle\Twig\AuthKitUiExtension;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use Twig\Environment;
use Twig\TwigFunction;

use function dirname;

/**
 * REQ-UI-003 page contract: every auth page extends the UiKit auth scaffold (guest layout), fills the canonical
 * blocks of the stub host layout, emits no CDN / inline handler / confirm(), paints framework classes only through
 * theme slots, reflects the stub theme and prints no raw translation keys.
 */
final class PageContractTest extends PageContractTestCase
{
    private const VIEWS = '@NowoAuthKitBundle/security/';

    public static function pageFixtures(): iterable
    {
        yield 'login' => new PageFixture(self::VIEWS . 'login.html.twig', AuthPageFixtures::login());
        yield 'login (bad credentials, providers)' => new PageFixture(self::VIEWS . 'login.html.twig', AuthPageFixtures::login(true, true));
        yield 'register' => new PageFixture(self::VIEWS . 'register.html.twig', AuthPageFixtures::register());
        yield 'register (slide gate)' => new PageFixture(self::VIEWS . 'register.html.twig', AuthPageFixtures::register('gate'));
        yield 'reset request' => new PageFixture(self::VIEWS . 'reset_request.html.twig', AuthPageFixtures::resetRequest());
        yield 'reset password' => new PageFixture(self::VIEWS . 'reset_password.html.twig', AuthPageFixtures::resetPassword());
        yield 'reset password (mismatch)' => new PageFixture(self::VIEWS . 'reset_password.html.twig', AuthPageFixtures::resetPassword(true));
        yield 'reset password code' => new PageFixture(self::VIEWS . 'reset_password_code.html.twig', AuthPageFixtures::resetPasswordCode());
        yield 'reset password code (invalid code)' => new PageFixture(self::VIEWS . 'reset_password_code.html.twig', AuthPageFixtures::resetPasswordCode(true));
        yield 'magic login request' => new PageFixture(self::VIEWS . 'magic_login_request.html.twig', AuthPageFixtures::magicLoginRequest());
        yield 'magic login confirm' => new PageFixture(self::VIEWS . 'magic_login_confirm.html.twig', AuthPageFixtures::magicLoginConfirm());
        yield 'qr login show' => new PageFixture(self::VIEWS . 'qr_login_show.html.twig', AuthPageFixtures::qrLoginShow());
        yield 'qr login show (stimulus, no image)' => new PageFixture(self::VIEWS . 'qr_login_show.html.twig', AuthPageFixtures::qrLoginShow('qr-login', false));
        yield 'qr login approve' => new PageFixture(self::VIEWS . 'qr_login_approve.html.twig', AuthPageFixtures::qrLoginApprove());
    }

    protected static function templatePaths(): array
    {
        return ['NowoAuthKitBundle' => dirname(__DIR__, 3) . '/src/Resources/views'];
    }

    protected static function translationDirs(): array
    {
        return [dirname(__DIR__, 3) . '/src/Resources/translations'];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 3) . '/docs/TEMPLATES.md';
    }

    protected function configureTwig(Environment $twig): void
    {
        $twig->addExtension(new AuthKitUiExtension(['form_theme' => AuthPageFixtures::FORM_THEMES], [], new AlwaysOutboundMailReadyChecker()));
        // SlideToConfirm asset helpers (registered by that bundle in an application).
        $twig->addFunction(new TwigFunction('nowo_slide_to_confirm_asset_path', static fn (string $path): string => 'bundles/nowoslidetoconfirm/' . $path));
        $twig->addFunction(new TwigFunction('nowo_slide_to_confirm_asset_package', static fn (): ?string => null));
    }
}
