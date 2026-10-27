<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/** SEC-004 P2: QR approval and the magic-login confirm interstitial never run without form CSRF protection. */
final class CsrfProtectionPassTest extends TestCase
{
    public function testRefusesQrLoginWithoutTheFormCsrfExtension(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('profiles.default.qr_login requires CSRF protection');

        (new CsrfProtectionPass())->process($this->container(['default' => ['qr_login' => ['mode' => 'enabled']]], csrf: false));
    }

    public function testRefusesTheConfirmInterstitialWhenFormCsrfIsDisabled(): void
    {
        $container = $this->container(['admin' => ['magic_login' => ['mode' => 'enabled', 'confirm_interstitial' => true]]], csrf: true);
        $container->setParameter('form.type_extension.csrf.enabled', false);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('profiles.admin.magic_login.confirm_interstitial');
        (new CsrfProtectionPass())->process($container);
    }

    public function testPassesWithCsrfOrWithoutCsrfDependentFeatures(): void
    {
        (new CsrfProtectionPass())->process($this->container(['default' => ['qr_login' => ['mode' => 'enabled']]], csrf: true));
        (new CsrfProtectionPass())->process($this->container([
            'default' => ['qr_login' => ['mode' => 'disabled'], 'magic_login' => ['mode' => 'enabled', 'confirm_interstitial' => false]],
            'broken'  => 'not-an-array',
        ], csrf: false));
        (new CsrfProtectionPass())->process(new ContainerBuilder());
        $scalar = new ContainerBuilder();
        $scalar->setParameter('nowo_auth_kit.profiles', 'x');
        (new CsrfProtectionPass())->process($scalar);

        $this->addToAssertionCount(1);
    }

    /**
     * @param array<string, mixed> $profiles
     */
    private function container(array $profiles, bool $csrf): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_auth_kit.profiles', $profiles);
        if ($csrf) {
            $container->setDefinition('security.csrf.token_manager', new Definition(stdClass::class));
            $container->setDefinition('form.type_extension.csrf', new Definition(stdClass::class));
        }

        return $container;
    }
}
