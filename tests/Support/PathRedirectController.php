<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support;

use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Redirects to the route's `path` default. Test kernels use it instead of the framework's RedirectController, whose
 * service Symfony 8.2 deprecates in favour of the `redirect_to` route option (not available before 8.2).
 */
final class PathRedirectController
{
    public function redirect(string $path, bool $permanent = false): RedirectResponse
    {
        return new RedirectResponse($path, $permanent ? 301 : 302);
    }
}
