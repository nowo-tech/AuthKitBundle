<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\ValueObject;

use Nowo\AuthKitBundle\ValueObject\AuthRequestContext;
use PHPUnit\Framework\TestCase;

use function json_encode;
use function print_r;
use function serialize;
use function unserialize;
use function var_export;

final class AuthRequestContextTest extends TestCase
{
    public function testPlainContextReadsNothing(): void
    {
        $context = new AuthRequestContext('GET', '/login');

        self::assertSame('GET', $context->method);
        self::assertSame('/login', $context->path);
        self::assertNull($context->routeName);
        self::assertNull($context->clientIp);
        self::assertNull($context->userAgent);
        self::assertSame('', $context->locale);
        self::assertNull($context->firewall);
        self::assertNull($context->profile);
        self::assertNull($context->header('X-Test'));
        self::assertNull($context->query('q'));
        self::assertNull($context->post('p'));
        self::assertFalse($context->sessionHas('k'));
        self::assertNull($context->sessionGet('k'));
        self::assertNull($context->sessionRemove('k'));
    }

    public function testFromValuesReadsTheArrays(): void
    {
        $context = AuthRequestContext::fromValues(
            'POST',
            '/approve',
            'route',
            '10.0.0.1',
            'UA',
            'es',
            'main',
            'default',
            ['X-Step-Up' => 'ok'],
            ['q' => 'v', 'arr' => ['x'], 'n' => 3],
            ['pin' => '1234', 'flag' => true],
            ['unlock' => 'yes', 'nil' => null],
        );

        self::assertSame(['POST', '/approve', 'route', '10.0.0.1', 'UA', 'es', 'main', 'default'], [$context->method, $context->path, $context->routeName, $context->clientIp, $context->userAgent, $context->locale, $context->firewall, $context->profile]);
        self::assertSame('ok', $context->header('x-step-up'));
        self::assertSame('ok', $context->header('X-STEP-UP'));
        self::assertNull($context->header('missing'));
        self::assertSame('v', $context->query('q'));
        self::assertNull($context->query('arr'));
        self::assertSame('3', $context->query('n'));
        self::assertSame('1234', $context->post('pin'));
        self::assertSame('1', $context->post('flag'));
        self::assertTrue($context->sessionHas('unlock'));
        self::assertTrue($context->sessionHas('nil'));
        self::assertFalse($context->sessionHas('missing'));
        self::assertSame('yes', $context->sessionGet('unlock'));
        self::assertSame('yes', $context->sessionRemove('unlock'));
        self::assertFalse($context->sessionHas('unlock'));
        self::assertNull($context->sessionRemove('unlock'));
    }

    public function testWithReaderAttachesTheClosures(): void
    {
        $removed = [];
        $context = AuthRequestContext::withReader(
            new AuthRequestContext('GET', '/'),
            static fn (string $bag, string $name): string => $bag . ':' . $name,
            static fn (string $name): bool => $name === 'yes',
            static function (string $name) use (&$removed): string {
                $removed[] = $name;

                return 'old';
            },
        );

        self::assertSame('header:H', $context->header('H'));
        self::assertSame('query:q', $context->query('q'));
        self::assertSame('post:p', $context->post('p'));
        self::assertSame('session:s', $context->sessionGet('s'));
        self::assertTrue($context->sessionHas('yes'));
        self::assertFalse($context->sessionHas('no'));
        self::assertSame('old', $context->sessionRemove('r'));
        self::assertSame(['r'], $removed);
    }

    public function testDumpsCloneAndUnserializedCopyNeverShowTheValues(): void
    {
        $context = AuthRequestContext::fromValues('GET', '/', headers: ['Cookie' => 'SESSID=COOKIE-SECRET'], post: ['_csrf_token' => 'CSRF-SECRET'], session: ['k' => 'SESSION-SECRET']);

        foreach ([var_export($context, true), print_r($context, true), (string) json_encode($context), serialize($context), print_r((array) $context, true)] as $dump) {
            self::assertStringNotContainsString('COOKIE-SECRET', $dump);
            self::assertStringNotContainsString('CSRF-SECRET', $dump);
            self::assertStringNotContainsString('SESSION-SECRET', $dump);
        }

        self::assertNull((clone $context)->header('Cookie'));
        $copy = unserialize(serialize($context));
        self::assertInstanceOf(AuthRequestContext::class, $copy);
        self::assertNull($copy->sessionGet('k'));
        self::assertFalse($copy->sessionHas('k'));
        self::assertNull($copy->sessionRemove('k'));
    }
}
