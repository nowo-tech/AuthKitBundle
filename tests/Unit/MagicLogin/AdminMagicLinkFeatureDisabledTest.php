<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\MagicLogin;

use Nowo\AuthKitBundle\MagicLogin\AdminMagicLinkIssuer;
use Nowo\AuthKitBundle\MagicLogin\AdminMagicLinkRefusedException;
use Nowo\AuthKitBundle\MagicLogin\NullMagicLoginNotifier;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * GY-L4: `magic_login.mode: disabled` also stops the administrator's links.
 */
final class AdminMagicLinkFeatureDisabledTest extends TestCase
{
    public function testRefusedWhileTheModeIsDisabled(): void
    {
        $issuer = $this->issuer('disabled');
        self::assertFalse($issuer->canIssue($this->user('admin@example.com'), $this->user('member@example.com')));

        try {
            $issuer->issue($this->user('admin@example.com'), $this->user('member@example.com'));
            self::fail('Expected a refusal.');
        } catch (AdminMagicLinkRefusedException $exception) {
            self::assertSame(AdminMagicLinkRefusedException::FEATURE_DISABLED, $exception->reason());
        }
    }

    public function testAllowedWhileEnabled(): void
    {
        self::assertTrue($this->issuer('enabled')->canIssue($this->user('admin@example.com'), $this->user('member@example.com')));
    }

    private function issuer(string $mode): AdminMagicLinkIssuer
    {
        return new AdminMagicLinkIssuer(
            new NullMagicLoginNotifier(),
            new RequestStack(),
            profileRegistry: ProfileRegistryFactory::single(TestUser::class, ['magic_login' => ['mode' => $mode]]),
        );
    }

    private function user(string $identifier): TestUser
    {
        $user = new TestUser();
        $user->setEmail($identifier);

        return $user;
    }
}
