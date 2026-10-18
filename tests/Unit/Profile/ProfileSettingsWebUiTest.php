<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Profile;

use Nowo\AuthKitBundle\DependencyInjection\Configuration;
use Nowo\AuthKitBundle\Tests\Support\AuthPageFixtures;
use PHPUnit\Framework\TestCase;

/**
 * ProfileSettings page-contract helpers: web_ui first, then the 1.x templates.* mirror, then the bundle defaults.
 */
final class ProfileSettingsWebUiTest extends TestCase
{
    public function testWebUiValuesWin(): void
    {
        $profile = AuthPageFixtures::profile(['web_ui' => [
            'layout_template' => 'layouts/guest.html.twig',
            'templates'       => ['login' => 'auth/login.html.twig'],
            'form_themes'     => ['form/auth.html.twig'],
        ]]);

        self::assertSame('auth/login.html.twig', $profile->template('login'));
        self::assertSame('layouts/guest.html.twig', $profile->layoutTemplate());
        self::assertSame('layouts/guest.html.twig', $profile->legacyLayoutTemplate());
        self::assertSame(['form/auth.html.twig'], $profile->formThemes());
    }

    public function testLegacyTemplatesMirrorWithoutWebUi(): void
    {
        $profile = AuthPageFixtures::profile(['templates' => ['layout' => 'old/layout.html.twig', 'login' => 'old/login.html.twig', 'form_theme' => ['old/form.html.twig', 3]]]);

        self::assertSame('old/login.html.twig', $profile->template('login'));
        self::assertSame('old/layout.html.twig', $profile->layoutTemplate());
        self::assertSame(['old/form.html.twig'], $profile->formThemes());
    }

    public function testDefaults(): void
    {
        $profile = AuthPageFixtures::profile(['templates' => ['layout' => Configuration::LEGACY_LAYOUT, 'login' => '', 'form_theme' => null]]);

        self::assertSame('@NowoAuthKitBundle/security/login.html.twig', $profile->template('login'));
        self::assertSame('@NowoAuthKitBundle/security/qr_login_show.html.twig', $profile->template('qr_login_show'));
        self::assertNull($profile->layoutTemplate());
        self::assertSame(Configuration::LEGACY_LAYOUT, $profile->legacyLayoutTemplate());
        self::assertSame(Configuration::defaultFormThemes(), $profile->formThemes());
    }
}
