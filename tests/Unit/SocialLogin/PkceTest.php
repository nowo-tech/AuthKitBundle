<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin;

use Nowo\AuthKitBundle\SocialLogin\Pkce;
use PHPUnit\Framework\TestCase;

final class PkceTest extends TestCase
{
    public function testChallengeMatchesRfc7636AppendixB(): void
    {
        self::assertSame(
            'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            Pkce::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'),
        );
        self::assertSame('S256', Pkce::METHOD);
    }

    public function testGeneratedVerifierIsUnreservedAndLongEnough(): void
    {
        $a = Pkce::generateVerifier();
        $b = Pkce::generateVerifier();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9\-._~]{43,128}$/', $a);
        self::assertNotSame($a, $b);
    }
}
