<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\QrLogin;

use Nowo\AuthKitBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class QrLoginConfigurationTest extends TestCase
{
    public function testDefaultsWhenQrLoginNotConfigured(): void
    {
        $processed = $this->process([
            'profiles' => [
                'default' => ['user_class' => 'App\\Entity\\User'],
            ],
        ]);

        $qrLogin = $processed['profiles']['default']['qr_login'];

        self::assertSame('disabled', $qrLogin['mode']);
        self::assertSame(90, $qrLogin['challenge_ttl']);
        self::assertSame(1500, $qrLogin['poll_interval_ms']);
        self::assertSame('session', $qrLogin['approve_requires']);
        self::assertSame('strict', $qrLogin['desktop_binding']);
        self::assertSame('phone', $qrLogin['phone_field']);
        self::assertSame('phoneVerifiedAt', $qrLogin['phone_verified_field']);
        self::assertSame(5, $qrLogin['create_rate_limit']);
        self::assertSame(600, $qrLogin['create_rate_window']);
        self::assertSame(5, $qrLogin['approve_rate_limit']);
    }

    public function testQrLoginEnabled(): void
    {
        $processed = $this->process([
            'profiles' => [
                'default' => [
                    'user_class' => 'App\\Entity\\User',
                    'qr_login'   => ['mode' => 'enabled'],
                ],
            ],
        ]);

        self::assertSame('enabled', $processed['profiles']['default']['qr_login']['mode']);
    }

    public function testCustomTtlClamped(): void
    {
        $processed = $this->process([
            'profiles' => [
                'default' => [
                    'user_class' => 'App\\Entity\\User',
                    'qr_login'   => ['mode' => 'enabled', 'challenge_ttl' => 120],
                ],
            ],
        ]);

        self::assertSame(120, $processed['profiles']['default']['qr_login']['challenge_ttl']);
    }

    public function testQrLoginRouteDefaults(): void
    {
        $processed = $this->process([
            'profiles' => [
                'default' => ['user_class' => 'App\\Entity\\User'],
            ],
        ]);

        $routes = $processed['profiles']['default']['routes'];

        self::assertSame('/login/qr', $routes['qr_login_start']['path']);
        self::assertSame('nowo_auth_kit_qr_login_start', $routes['qr_login_start']['name']);
        self::assertSame('/login/qr/{id}', $routes['qr_login_show']['path']);
        self::assertSame('/login/qr/{id}/status', $routes['qr_login_status']['path']);
        self::assertSame('/login/qr/{id}/complete', $routes['qr_login_complete']['path']);
        self::assertSame('/login/qr/{id}/approve', $routes['qr_login_approve']['path']);
        self::assertSame('/login/qr/{id}/deny', $routes['qr_login_deny']['path']);
    }

    public function testSessionStepUpApproveMode(): void
    {
        $processed = $this->process([
            'profiles' => [
                'default' => [
                    'user_class' => 'App\\Entity\\User',
                    'qr_login'   => ['approve_requires' => 'session_step_up'],
                ],
            ],
        ]);

        self::assertSame('session_step_up', $processed['profiles']['default']['qr_login']['approve_requires']);
    }

    public function testDesktopBindingSoft(): void
    {
        $processed = $this->process([
            'profiles' => [
                'default' => [
                    'user_class' => 'App\\Entity\\User',
                    'qr_login'   => ['desktop_binding' => 'soft'],
                ],
            ],
        ]);

        self::assertSame('soft', $processed['profiles']['default']['qr_login']['desktop_binding']);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}
