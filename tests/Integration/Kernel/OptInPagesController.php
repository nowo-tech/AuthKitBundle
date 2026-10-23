<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Kernel;

use Nowo\AuthKitBundle\PasswordReset\PasswordResetCheckEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

use function sprintf;

/**
 * Host pages of {@see OptInTestKernel}: the check-email page and a page with the embed widget.
 */
final class OptInPagesController
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function checkEmail(Request $request): Response
    {
        $checkEmail = PasswordResetCheckEmail::fromRequest($request);

        return new Response($checkEmail instanceof PasswordResetCheckEmail ? sprintf('ttl=%d throttled=%d', $checkEmail->tokenTtl, (int) $checkEmail->throttled) : 'none');
    }

    public function embed(): Response
    {
        return new Response($this->twig->createTemplate('<html><body>{{ auth_kit_dropdown() }}</body></html>')->render());
    }
}
