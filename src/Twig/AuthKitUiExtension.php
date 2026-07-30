<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Twig;

use Nowo\AuthKitBundle\Mailer\OutboundMailReadyCheckerInterface;
use Twig\Attribute\AsTwigFunction;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

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
            'nowo_auth_kit_form_themes'            => $formThemes,
            'nowo_auth_kit_button_class'           => $this->css['button_class'] ?? 'nowo-auth-kit__button',
            'nowo_auth_kit_secondary_button_class' => $this->css['secondary_button_class'] ?? 'nowo-auth-kit__social-button',
        ];
    }

    #[AsTwigFunction('nowo_auth_kit_outbound_mail_ready')]
    public function isOutboundMailReady(): bool
    {
        return $this->outboundMailReadyChecker->isReady();
    }
}
