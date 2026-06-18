<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Twig;

use Nowo\AuthKitBundle\Embed\AuthEmbedContextFactory;
use Twig\Attribute\AsTwigFunction;
use Twig\Environment;

/**
 * Renders embedded login/register UI for any page.
 */
final class AuthEmbedExtension
{
    public function __construct(
        private readonly AuthEmbedContextFactory $contextFactory,
        private readonly Environment $twig,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    #[AsTwigFunction('auth_kit_dropdown', isSafe: ['html'])]
    public function renderDropdown(array $options = []): string
    {
        $context = $this->contextFactory->create($options);

        if (!$context instanceof \Nowo\AuthKitBundle\Embed\AuthEmbedContext) {
            return '';
        }

        return $this->twig->render($context->template, array_merge($context->toArray(), $options));
    }
}
