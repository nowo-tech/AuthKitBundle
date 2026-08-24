<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Twig;

use Nowo\AuthKitBundle\Mailer\AlwaysOutboundMailReadyChecker;
use Nowo\AuthKitBundle\Mailer\OutboundMailReadyCheckerInterface;
use Nowo\AuthKitBundle\Twig\AuthKitUiExtension;
use PHPUnit\Framework\TestCase;

use function class_exists;

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
                'nowo_auth_kit_form_themes'                          => ['form/auth_kit_theme.html.twig'],
                'nowo_auth_kit_button_class'                         => 'btn btn-primary',
                'nowo_auth_kit_secondary_button_class'               => 'nowo-auth-kit__social-button',
                'nowo_auth_kit_slide_to_confirm_assets'              => false,
                'nowo_auth_kit_device_intelligence_assets'           => false,
                'nowo_auth_kit_device_intelligence_collect_endpoint' => '/_device/collect',
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

    public function testRegistersOutboundMailReadyTwigFunction(): void
    {
        $extension = new AuthKitUiExtension([], [], new AlwaysOutboundMailReadyChecker());
        $functions = $extension->getFunctions();

        self::assertCount(3, $functions);
        self::assertSame('nowo_auth_kit_outbound_mail_ready', $functions[0]->getName());
        self::assertSame('nowo_auth_kit_slide_to_confirm_assets', $functions[1]->getName());
        self::assertSame('nowo_auth_kit_device_intelligence_assets', $functions[2]->getName());
        $callable = $functions[0]->getCallable();
        self::assertIsCallable($callable);
        self::assertTrue($callable());
        $assetsCallable = $functions[1]->getCallable();
        self::assertIsCallable($assetsCallable);
        self::assertFalse($assetsCallable());
        $deviceCallable = $functions[2]->getCallable();
        self::assertIsCallable($deviceCallable);
        self::assertFalse($deviceCallable());
    }

    public function testSlideToConfirmAssetsRequiresConfigAndBundle(): void
    {
        $extension = new AuthKitUiExtension([], [], new AlwaysOutboundMailReadyChecker(), true);
        $expected  = class_exists('Nowo\\SlideToConfirmBundle\\Twig\\NowoSlideToConfirmTwigExtension');

        self::assertSame($expected, $extension->shouldLoadSlideToConfirmAssets());
        self::assertSame($expected, $extension->getGlobals()['nowo_auth_kit_slide_to_confirm_assets']);
    }

    public function testDeviceIntelligenceAssetsRequiresConfigAndBundle(): void
    {
        $extension = new AuthKitUiExtension([], [], new AlwaysOutboundMailReadyChecker(), false, true);
        $expected  = class_exists('Nowo\\DeviceIntelligenceBundle\\Request\\DeviceContext');

        self::assertSame($expected, $extension->shouldLoadDeviceIntelligenceAssets());
        self::assertSame($expected, $extension->getGlobals()['nowo_auth_kit_device_intelligence_assets']);
        self::assertSame('/_device/collect', $extension->getGlobals()['nowo_auth_kit_device_intelligence_collect_endpoint']);
    }
}
