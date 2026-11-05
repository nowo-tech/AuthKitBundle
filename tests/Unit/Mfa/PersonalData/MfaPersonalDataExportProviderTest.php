<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Mfa\PersonalData;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Mfa\PersonalData\MfaPersonalDataExportProvider;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use stdClass;

use function array_keys;
use function json_encode;

final class MfaPersonalDataExportProviderTest extends MfaPersonalDataTestCase
{
    public function testSectionAndPriority(): void
    {
        $provider = new MfaPersonalDataExportProvider($this->manager());

        self::assertSame('mfa', $provider->getSectionKey());
        self::assertSame(-50, MfaPersonalDataExportProvider::PRIORITY);
    }

    public function testSupportsOnlyUsersOfAnMfaProfile(): void
    {

        self::assertTrue((new MfaPersonalDataExportProvider($this->manager()))->supports(new TestUser('member@example.com')));
        self::assertFalse((new MfaPersonalDataExportProvider($this->manager()))->supports(new stdClass()));
        self::assertFalse((new MfaPersonalDataExportProvider($this->manager(mfa: false)))->supports(new TestUser('member@example.com')));
    }

    public function testNullForUnsupportedUsersAndUsersWhoNeverEnrolled(): void
    {
        $provider = new MfaPersonalDataExportProvider($this->manager());

        self::assertNull($provider->export(new stdClass()));
        self::assertNull($provider->export(new TestUser('member@example.com')));
    }

    public function testExportsAnAllowListWithoutSecretsOrDeviceTokens(): void
    {
        $this->stored(7, [[
            'public_id'    => 'PUBLICID',
            'label'        => 'Firefox on Linux',
            'device_id'    => 'USERKITDEVICE',
            'created_at'   => '2026-11-03 10:00:00',
            'last_used_at' => '2026-11-04 10:00:00',
            'expires_at'   => '2026-12-03 10:00:00',
        ]]);
        $this->add('default', 'member@example.com', enabled: true);

        $export = (new MfaPersonalDataExportProvider($this->manager()))->export(new TestUser('member@example.com'));

        self::assertIsArray($export);
        self::assertSame(['profile', 'method', 'enabled', 'enabled_at', 'required_since', 'reset_at', 'unused_recovery_codes', 'trusted_devices'], array_keys($export));
        self::assertSame('default', $export['profile']);
        self::assertSame('totp', $export['method']);
        self::assertTrue($export['enabled']);
        self::assertEquals(new DateTimeImmutable('2026-11-02 10:00:00'), $export['enabled_at']);
        self::assertSame(7, $export['unused_recovery_codes']);
        self::assertCount(1, $export['trusted_devices']);
        self::assertSame(['label', 'created_at', 'last_used_at', 'expires_at'], array_keys($export['trusted_devices'][0]));
        self::assertSame('Firefox on Linux', $export['trusted_devices'][0]['label']);
        $json = (string) json_encode($export);
        foreach (['PLACEHOLDERSECRET', 'PUBLICID', 'USERKITDEVICE'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
    }

    public function testADisabledCredentialHasNoMethodAndNoCodes(): void
    {
        $this->stored(7);
        $this->add('default', 'member@example.com');

        $export = (new MfaPersonalDataExportProvider($this->manager()))->export(new TestUser('member@example.com'));

        self::assertIsArray($export);
        self::assertNull($export['method']);
        self::assertFalse($export['enabled']);
        self::assertSame(0, $export['unused_recovery_codes']);
    }
}
