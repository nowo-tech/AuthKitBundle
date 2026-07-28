<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Twig;

use Nowo\AuthKitBundle\Embed\AuthEmbedContext;
use Nowo\AuthKitBundle\Embed\AuthEmbedContextFactory;
use Nowo\AuthKitBundle\Embed\AuthEmbedOptions;
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
     * @param array<string, mixed>|AuthEmbedOptions $options
     */
    #[AsTwigFunction('auth_kit_dropdown', isSafe: ['html'])]
    public function renderDropdown(AuthEmbedOptions|array $options = []): string
    {
        $opts    = $options instanceof AuthEmbedOptions ? $options : AuthEmbedOptions::fromArray($options);
        $context = $this->contextFactory->create($opts);

        if (!$context instanceof AuthEmbedContext) {
            return '';
        }

        return $this->twig->render($context->template, array_merge($context->toArray(), $opts->toArray()));
    }
}
