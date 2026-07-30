<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Twig;

use Nowo\AuthKitBundle\Mailer\AlwaysOutboundMailReadyChecker;
use Nowo\AuthKitBundle\Mailer\OutboundMailReadyCheckerInterface;
use Nowo\AuthKitBundle\Twig\AuthKitUiExtension;
use PHPUnit\Framework\TestCase;

final class AuthKitUiExtensionTest extends TestCase
{
    public function testExposesDefaultGlobals(): void
    {
        $extension = new AuthKitUiExtension(
            [
                'form_theme' => ['form/auth_kit_theme.html.twig'],
            ],
            [
                'button_class' => 'btn btn-primary',
            ],
            new AlwaysOutboundMailReadyChecker(),
        );

        self::assertSame(
            [
                'nowo_auth_kit_form_themes'            => ['form/auth_kit_theme.html.twig'],
                'nowo_auth_kit_button_class'           => 'btn btn-primary',
                'nowo_auth_kit_secondary_button_class' => 'nowo-auth-kit__social-button',
            ],
            $extension->getGlobals(),
        );
    }

    public function testReportsOutboundMailAsReadyByDefault(): void
    {
        $extension = new AuthKitUiExtension([], [], new AlwaysOutboundMailReadyChecker());

        self::assertTrue($extension->isOutboundMailReady());
    }

    public function testFallsBackToDefaultFormThemeAndReportsCustomCheckerState(): void
    {
        $extension = new AuthKitUiExtension([], [], new class implements OutboundMailReadyCheckerInterface {
            public function isReady(): bool
            {
                return false;
            }
        });

        self::assertSame(
            ['@NowoPasswordToggleBundle/Form/toggle_password_widget.html.twig'],
            $extension->getGlobals()['nowo_auth_kit_form_themes'],
        );
        self::assertFalse($extension->isOutboundMailReady());
    }
}
