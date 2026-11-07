<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\ProgrammaticLogin;

final class OnlyCustomKernel extends ProgrammaticLoginTestKernel
{
    protected function authenticators(): array
    {
        return ['custom_authenticators' => [StubAuthenticator::class]];
    }
}
