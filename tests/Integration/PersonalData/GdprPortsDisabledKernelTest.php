<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PersonalData;

use Nowo\AuthKitBundle\PersonalData\DoctrineInvitationPersonalData;
use Nowo\AuthKitBundle\PersonalData\InvitationUserKitEraser;
use Nowo\AuthKitBundle\PersonalData\InvitationUserKitExportProvider;
use Nowo\AuthKitBundle\PersonalData\SocialAccountPersonalDataEraser;
use Nowo\AuthKitBundle\PersonalData\SocialAccountUserKitExportProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `personal_data.enabled: false`: no 1.45 UserKit adapter; the ports and the 1.37 social eraser stay.
 */
final class GdprPortsDisabledKernelTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return GdprPortsDisabledTestKernel::class;
    }

    public function testOnlyThePortsAreRegistered(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(DoctrineInvitationPersonalData::class, $container->get('test.invitation_eraser'));
        self::assertFalse($container->has(InvitationUserKitEraser::class));
        self::assertFalse($container->has(InvitationUserKitExportProvider::class));
        self::assertFalse($container->has(SocialAccountUserKitExportProvider::class));
        self::assertTrue($container->has(SocialAccountPersonalDataEraser::class));
    }
}
