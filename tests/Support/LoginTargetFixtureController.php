<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controllers of the login target fixtures: pages and machine endpoints (by declared return type).
 */
final class LoginTargetFixtureController
{
    public function page(): Response
    {
        return new Response('<!doctype html><title>Page</title><p>page</p>');
    }

    public function json(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }

    public function stream(): StreamedResponse
    {
        return new StreamedResponse(static function (): void {
            echo 'data: x';
        });
    }

    public function file(): BinaryFileResponse
    {
        return new BinaryFileResponse(__FILE__);
    }

    public function untyped(): mixed
    {
        return new Response('untyped');
    }

    public function __invoke(): Response
    {
        return $this->page();
    }
}
