<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Webauthn;

use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Webauthn\WebauthnCeremonies;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;

/**
 * Audit ZCC-L1: an env-driven `allowed_origins` entry resolves (the build keeps the placeholder as written) and the
 * resolved origin is compared lower-cased (audit ZBX-v-I3).
 */
final class WebauthnEnvOriginKernelTest extends WebauthnKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return WebauthnEnvOriginTestKernel::class;
    }

    #[Test]
    public function anEnvOriginResolvesAndIsLowerCased(): void
    {
        $profiles = self::getContainer()->get(ProfileRegistry::class);
        self::assertInstanceOf(ProfileRegistry::class, $profiles);
        $ceremonies = self::getContainer()->get(WebauthnCeremonies::class);
        self::assertInstanceOf(WebauthnCeremonies::class, $ceremonies);

        self::assertSame(['https://login.example.com:8443'], $ceremonies->allowedOrigins($profiles->getByName('default'), Request::create('https://login.example.com:8443/login')));
    }
}
