<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Kernel;

use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Host page of {@see LocalizedLogoutTestKernel}: prints the firewall logout URL (with its CSRF token).
 */
final class LocalizedLogoutPagesController
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function logoutLink(): Response
    {
        return new Response($this->twig->createTemplate('{{ logout_path("main") }}')->render());
    }
}
