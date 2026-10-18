<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Twig;

use Nowo\AuthKitBundle\DependencyInjection\Configuration;
use Nowo\AuthKitBundle\Mailer\OutboundMailReadyCheckerInterface;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

use function class_exists;
use function is_array;

/**
 * Exposes Auth Kit UI defaults and feature checks to Twig templates.
 */
final class AuthKitUiExtension extends AbstractExtension implements GlobalsInterface
{
    /**
     * @param array<string, mixed> $templates
     * @param array{button_class?: string, secondary_button_class?: string} $css
     */
    public function __construct(
        private readonly array $templates,
        private readonly array $css,
        private readonly OutboundMailReadyCheckerInterface $outboundMailReadyChecker,
        private readonly bool $slideToConfirmAssets = false,
        private readonly bool $deviceIntelligenceAssets = false,
        private readonly string $deviceIntelligenceCollectEndpoint = '/_device/collect',
        private readonly bool $otpInputAssets = false,
        private readonly ?bool $passwordToggleAssets = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getGlobals(): array
    {
        /** @var list<string> $formThemes */
        $formThemes = isset($this->templates['form_theme']) && is_array($this->templates['form_theme'])
            ? array_values(array_filter($this->templates['form_theme'], 'is_string'))
            : Configuration::defaultFormThemes();

        return [
            'nowo_auth_kit_form_themes' => $formThemes,
            // 1.x (deprecated with css.*): pages use the UiKit btn_primary / btn_secondary slots.
            'nowo_auth_kit_button_class'                         => $this->css['button_class'] ?? 'nowo-auth-kit__button',
            'nowo_auth_kit_secondary_button_class'               => $this->css['secondary_button_class'] ?? 'nowo-auth-kit__social-button',
            'nowo_auth_kit_slide_to_confirm_assets'              => $this->shouldLoadSlideToConfirmAssets(),
            'nowo_auth_kit_device_intelligence_assets'           => $this->shouldLoadDeviceIntelligenceAssets(),
            'nowo_auth_kit_device_intelligence_collect_endpoint' => $this->deviceIntelligenceCollectEndpoint,
            'nowo_auth_kit_otp_input_assets'                     => $this->shouldLoadOtpInputAssets(),
            'nowo_auth_kit_password_toggle_assets'               => $this->shouldLoadPasswordToggleAssets(),
        ];
    }

    public function getFunctions(): array
    {
        $functions = [
            new TwigFunction('nowo_auth_kit_outbound_mail_ready', $this->isOutboundMailReady(...)),
            new TwigFunction('nowo_auth_kit_slide_to_confirm_assets', $this->shouldLoadSlideToConfirmAssets(...)),
            new TwigFunction('nowo_auth_kit_device_intelligence_assets', $this->shouldLoadDeviceIntelligenceAssets(...)),
            new TwigFunction('nowo_auth_kit_otp_input_assets', $this->shouldLoadOtpInputAssets(...)),
        ];

        // Optional bundle Twig functions are referenced in _slide_to_confirm_assets.html.twig;
        // register no-op stubs so templates compile when the package is not installed.
        if (!class_exists('Nowo\\SlideToConfirmBundle\\Twig\\NowoSlideToConfirmTwigExtension')) {
            $functions[] = new TwigFunction('nowo_slide_to_confirm_asset_path', static fn (): string => ''); // @codeCoverageIgnore
            $functions[] = new TwigFunction('nowo_slide_to_confirm_asset_package', static fn (): string => ''); // @codeCoverageIgnore
        }

        return $functions;
    }

    public function isOutboundMailReady(): bool
    {
        return $this->outboundMailReadyChecker->isReady();
    }

    public function shouldLoadSlideToConfirmAssets(): bool
    {
        return $this->slideToConfirmAssets
            && class_exists('Nowo\\SlideToConfirmBundle\\Twig\\NowoSlideToConfirmTwigExtension');
    }

    public function shouldLoadDeviceIntelligenceAssets(): bool
    {
        return $this->deviceIntelligenceAssets
            && class_exists('Nowo\\DeviceIntelligenceBundle\\Request\\DeviceContext');
    }

    /**
     * PasswordToggle stylesheet on the auth pages: only when that bundle is registered (null: installed).
     */
    public function shouldLoadPasswordToggleAssets(): bool
    {
        return $this->passwordToggleAssets ?? class_exists(Configuration::PASSWORD_TOGGLE_TYPE);
    }

    public function shouldLoadOtpInputAssets(): bool
    {
        return $this->otpInputAssets
            && class_exists('Nowo\\OtpInputBundle\\Form\\OtpType');
    }
}
