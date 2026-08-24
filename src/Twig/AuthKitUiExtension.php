<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Twig;

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
            : ['@NowoPasswordToggleBundle/Form/toggle_password_widget.html.twig'];

        return [
            'nowo_auth_kit_form_themes'                          => $formThemes,
            'nowo_auth_kit_button_class'                         => $this->css['button_class'] ?? 'nowo-auth-kit__button',
            'nowo_auth_kit_secondary_button_class'               => $this->css['secondary_button_class'] ?? 'nowo-auth-kit__social-button',
            'nowo_auth_kit_slide_to_confirm_assets'              => $this->shouldLoadSlideToConfirmAssets(),
            'nowo_auth_kit_device_intelligence_assets'           => $this->shouldLoadDeviceIntelligenceAssets(),
            'nowo_auth_kit_device_intelligence_collect_endpoint' => $this->deviceIntelligenceCollectEndpoint,
            'nowo_auth_kit_otp_input_assets'                     => $this->shouldLoadOtpInputAssets(),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('nowo_auth_kit_outbound_mail_ready', $this->isOutboundMailReady(...)),
            new TwigFunction('nowo_auth_kit_slide_to_confirm_assets', $this->shouldLoadSlideToConfirmAssets(...)),
            new TwigFunction('nowo_auth_kit_device_intelligence_assets', $this->shouldLoadDeviceIntelligenceAssets(...)),
            new TwigFunction('nowo_auth_kit_otp_input_assets', $this->shouldLoadOtpInputAssets(...)),
        ];
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

    public function shouldLoadOtpInputAssets(): bool
    {
        return $this->otpInputAssets
            && class_exists('Nowo\\OtpInputBundle\\Form\\OtpType');
    }
}
