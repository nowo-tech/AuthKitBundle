<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Kernel;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * ux_icon() stub for the PasswordToggle widget theme (symfony/ux-icons is not a test dependency).
 */
final class UxIconStubExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('ux_icon', static fn (string $name, array $attributes = []): string => '<svg aria-hidden="true"></svg>', ['is_safe' => ['html']])];
    }
}
