<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Contract;

use Nowo\AuthKitBundle\Mailer\AlwaysOutboundMailReadyChecker;
use Nowo\AuthKitBundle\Tests\Support\AuthPageFixtures;
use Nowo\AuthKitBundle\Twig\AuthKitUiExtension;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use Symfony\Component\DomCrawler\Crawler;
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
        yield 'reset request (code delivery)' => new PageFixture(self::VIEWS . 'reset_request.html.twig', AuthPageFixtures::resetRequest('both'));
        yield 'reset password' => new PageFixture(self::VIEWS . 'reset_password.html.twig', AuthPageFixtures::resetPassword());
        yield 'reset password (mismatch)' => new PageFixture(self::VIEWS . 'reset_password.html.twig', AuthPageFixtures::resetPassword(true));
        yield 'reset password code' => new PageFixture(self::VIEWS . 'reset_password_code.html.twig', AuthPageFixtures::resetPasswordCode());
        yield 'reset password code (invalid code)' => new PageFixture(self::VIEWS . 'reset_password_code.html.twig', AuthPageFixtures::resetPasswordCode(true));
        yield 'magic login request' => new PageFixture(self::VIEWS . 'magic_login_request.html.twig', AuthPageFixtures::magicLoginRequest());
        yield 'magic login confirm' => new PageFixture(self::VIEWS . 'magic_login_confirm.html.twig', AuthPageFixtures::magicLoginConfirm());
        yield 'qr login show' => new PageFixture(self::VIEWS . 'qr_login_show.html.twig', AuthPageFixtures::qrLoginShow());
        yield 'qr login show (stimulus, no image)' => new PageFixture(self::VIEWS . 'qr_login_show.html.twig', AuthPageFixtures::qrLoginShow('qr-login', false));
        yield 'qr login show (stimulus, image)' => new PageFixture(self::VIEWS . 'qr_login_show.html.twig', AuthPageFixtures::qrLoginShow('qr-login'));
        yield 'qr login approve' => new PageFixture(self::VIEWS . 'qr_login_approve.html.twig', AuthPageFixtures::qrLoginApprove());
    }

    /**
     * Stimulus resolves targets only inside the controller element: the continue/retry actions (outside the
     * challenge root, in the form actions) must be descendants of the element carrying data-controller.
     */
    public function testQrStimulusControllerWrapsEveryTarget(): void
    {
        foreach ([true, false] as $withImage) {
            $crawler    = new Crawler($this->renderPage(new PageFixture(self::VIEWS . 'qr_login_show.html.twig', AuthPageFixtures::qrLoginShow('qr-login', $withImage)))->html);
            $controller = $crawler->filter('[data-controller="qr-login"]');

            self::assertCount(1, $controller);
            self::assertSame('auth-kit-qr_login_show', $controller->attr('data-testid'));
            self::assertSame('/login/qr/0b1c2d3e-4f50-4a61-8b72-93a4b5c6d7e8/status', $controller->attr('data-qr-login-status-url-value'));
            self::assertSame('/login/qr/0b1c2d3e-4f50-4a61-8b72-93a4b5c6d7e8/complete', $controller->attr('data-qr-login-complete-url-value'));
            self::assertSame('/login/qr/start', $controller->attr('data-qr-login-start-url-value'));
            self::assertSame('1500', $controller->attr('data-qr-login-poll-interval-value'));
            self::assertSame('1760000090', $controller->attr('data-qr-login-expires-at-value'));

            $all    = $crawler->filter('[data-qr-login-target]')->each(static fn (Crawler $node): string => (string) $node->attr('data-qr-login-target'));
            $inside = $controller->filter('[data-qr-login-target]')->each(static fn (Crawler $node): string => (string) $node->attr('data-qr-login-target'));
            self::assertSame($all, $inside);
            foreach (['image', 'code', 'status', 'countdown', 'continue', 'retry'] as $target) {
                self::assertContains($target, $inside);
            }
            self::assertCount(0, $crawler->filter('[data-nowo-auth-kit-qr][data-controller]'));
        }

        $plain = new Crawler($this->renderPage(new PageFixture(self::VIEWS . 'qr_login_show.html.twig', AuthPageFixtures::qrLoginShow()))->html);
        self::assertCount(0, $plain->filter('[data-controller]'));
        self::assertCount(0, $plain->filter('[data-qr-login-target]'));
    }

    /**
     * The approve link is offered with and without a QR image (same-device flow, screen readers).
     */
    public function testQrApproveLinkIsAlwaysRendered(): void
    {
        $payload = 'https://app.example.com/login/qr/0b1c2d3e-4f50-4a61-8b72-93a4b5c6d7e8/approve?t=token';

        $withImage = new Crawler($this->renderPage(new PageFixture(self::VIEWS . 'qr_login_show.html.twig', AuthPageFixtures::qrLoginShow()))->html);
        self::assertSame('img', $withImage->filter('[data-nowo-auth-kit-qr-region="image"]')->nodeName());
        self::assertSame($payload, $withImage->filter('[data-nowo-auth-kit-qr-region="link"] a')->attr('href'));
        self::assertSame('Open approve link on your phone', trim($withImage->filter('[data-nowo-auth-kit-qr-region="link"] a')->text()));

        $withoutImage = new Crawler($this->renderPage(new PageFixture(self::VIEWS . 'qr_login_show.html.twig', AuthPageFixtures::qrLoginShow(null, false)))->html);
        self::assertSame($payload, $withoutImage->filter('[data-nowo-auth-kit-qr-region="image"] a')->attr('href'));
        self::assertCount(0, $withoutImage->filter('[data-nowo-auth-kit-qr-region="link"]'));
        self::assertCount(1, $withoutImage->filter('a[href="' . $payload . '"]'));
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
