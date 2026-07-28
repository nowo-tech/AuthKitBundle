<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Embed;

use function array_diff_key;
use function array_flip;
use function is_string;

/**
 * Typed options for embedded auth UI (Twig `auth_kit_dropdown` / factory).
 *
 * Arrays remain accepted for Twig/BC; prefer this DTO from PHP callers.
 */
final readonly class AuthEmbedOptions
{
    private const KNOWN_KEYS = ['profile', 'active_panel', 'template', 'form_theme'];

    /**
     * @param array<string, mixed> $extra Extra keys merged into the Twig context
     */
    public function __construct(
        public ?string $profile = null,
        public ?string $activePanel = null,
        public ?string $template = null,
        public ?string $formTheme = null,
        public array $extra = [],
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        $profile = isset($options['profile']) && is_string($options['profile']) && $options['profile'] !== ''
            ? $options['profile']
            : null;
        $activePanel = isset($options['active_panel']) && is_string($options['active_panel'])
            ? $options['active_panel']
            : null;
        $template = isset($options['template']) && is_string($options['template'])
            ? $options['template']
            : null;
        $formTheme = isset($options['form_theme']) && is_string($options['form_theme'])
            ? $options['form_theme']
            : null;

        /** @var array<string, mixed> $extra */
        $extra = array_diff_key($options, array_flip(self::KNOWN_KEYS));

        return new self($profile, $activePanel, $template, $formTheme, $extra);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = $this->extra;
        if ($this->profile !== null) {
            $out['profile'] = $this->profile;
        }
        if ($this->activePanel !== null) {
            $out['active_panel'] = $this->activePanel;
        }
        if ($this->template !== null) {
            $out['template'] = $this->template;
        }
        if ($this->formTheme !== null) {
            $out['form_theme'] = $this->formTheme;
        }

        return $out;
    }
}
