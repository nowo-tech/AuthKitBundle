<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\View;

use InvalidArgumentException;
use Nowo\AuthKitBundle\DependencyInjection\Configuration;
use Nowo\AuthKitBundle\Tests\Support\AuthPageFixtures;
use Nowo\AuthKitBundle\View\AuthPageFactory;
use Nowo\UiKitBundle\View\Label;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

final class AuthPageFactoryTest extends TestCase
{
    public function testBuildsTheAuthPageOfAProfile(): void
    {
        $profile = AuthPageFixtures::profile(['web_ui' => ['layout_template' => 'layouts/guest.html.twig', 'brand' => 'Acme']]);
        $factory = new AuthPageFactory();
        $page    = $factory->create('reset_request', $profile, links: [$factory->link('reset.request.back_to_login', '/login')], attributes: ['data-turbo' => 'false']);

        self::assertEquals(new Label('reset.request.heading', 'NowoAuthKitBundle'), $page->title);
        self::assertEquals(new Label('reset.request.help', 'NowoAuthKitBundle'), $page->intro);
        self::assertEquals(new Label('reset.request.submit', 'NowoAuthKitBundle'), $page->submitLabel);
        self::assertSame('Acme', $page->brand);
        self::assertSame('NowoAuthKitBundle', $page->bundle);
        self::assertSame('layouts/guest.html.twig', $page->layout);
        self::assertSame(['data-testid' => 'auth-kit-reset_request', 'data-nowo-auth-kit-page' => 'reset_request', 'data-turbo' => 'false'], $page->attributes);
        self::assertSame('/login', $page->links[0]->href);
    }

    public function testPagesWithoutIntroAndEmptyBrand(): void
    {
        $page = (new AuthPageFactory())->create('login', AuthPageFixtures::profile(['web_ui' => ['brand' => '']]));

        self::assertNull($page->intro);
        self::assertNull($page->brand);
        self::assertNull($page->layout);
    }

    public function testContextCarriesTheSharedVariables(): void
    {
        $profile = AuthPageFixtures::profile(['web_ui' => ['form_themes' => ['form/auth.html.twig']]]);
        $factory = new AuthPageFactory();
        $page    = $factory->create('magic_login_request', $profile);
        $context = $factory->context('magic_login_request', $page, $profile, ['login_route' => 'login', 'page' => 'ignored']);

        self::assertSame($page, $context['page']);
        self::assertSame('magic_login_request', $context['auth_kit_page']);
        self::assertEquals(new Label('magic_login.request.title', 'NowoAuthKitBundle'), $context['document_title']);
        self::assertSame(['form/auth.html.twig'], $context['form_themes']);
        self::assertSame(Configuration::LEGACY_LAYOUT, $context['layout_template']);
        self::assertSame('login', $context['login_route']);
    }

    public function testUnknownPage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown AuthKit page "nope"');

        (new AuthPageFactory())->create('nope', AuthPageFixtures::profile());
    }

    public function testAuthenticationErrorBecomesASecurityLabel(): void
    {
        $factory = new AuthPageFactory();
        self::assertNull($factory->authenticationError(null));

        $error = new CustomUserMessageAuthenticationException('Too many attempts, retry in %minutes% minutes.', ['%minutes%' => 5, '%locked%' => true, '%user%' => new stdClass()]);
        self::assertEquals(
            new Label('Too many attempts, retry in %minutes% minutes.', 'security', ['%minutes%' => 5, '%locked%' => 1]),
            $factory->authenticationError($error),
        );
    }

    public function testPages(): void
    {
        self::assertSame(array_keys(Configuration::PAGE_TEMPLATES), AuthPageFactory::pages());
    }
}
