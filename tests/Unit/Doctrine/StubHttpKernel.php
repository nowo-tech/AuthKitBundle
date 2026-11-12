<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Doctrine;

use LogicException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Kernel placeholder for kernel events built by {@see BoundaryEvents}; never handles anything.
 */
final class StubHttpKernel implements HttpKernelInterface
{
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        throw new LogicException('Not used.');
    }
}
