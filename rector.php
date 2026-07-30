<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        __DIR__ . '/demo',
        __DIR__ . '/vendor',
        // Symfony 7.4 UserInterface still requires empty eraseCredentials(); Symfony 8 dropped it.
        RemoveEmptyClassMethodRector::class => [
            __DIR__ . '/tests/Stub',
            __DIR__ . '/tests/Unit/SocialLogin/SocialAccountLinkerTest.php',
            __DIR__ . '/tests/Unit/MagicLogin/MagicLoginRequestHandlerTest.php',
        ],
    ])
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    );
