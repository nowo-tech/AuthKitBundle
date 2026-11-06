<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\PersonalData;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Mfa\MfaOwnerResolver;
use Nowo\AuthKitBundle\Mfa\MfaStore;
use Nowo\AuthKitBundle\MultiAccount\AccountVaultStore;
use Nowo\AuthKitBundle\PersonalData\DormantAccountSwitcherPersonalDataEraser;
use Nowo\AuthKitBundle\PersonalData\DormantMfaPersonalDataEraser;
use Nowo\AuthKitBundle\PersonalData\SocialAccountPersonalDataEraser;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Something that is not a security user, or a user of no profile: nothing is supported, nothing is touched.
 */
final class NonUserErasureTest extends TestCase
{
    public function testNonUsersAreIgnored(): void
    {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects(self::never())->method('getManager');
        $registry = ProfileRegistryFactory::single(TestUser::class);
        $erasers  = [
            new DormantMfaPersonalDataEraser(new MfaStore($doctrine), new MfaOwnerResolver()),
            new DormantAccountSwitcherPersonalDataEraser(new AccountVaultStore($doctrine), $registry),
            new SocialAccountPersonalDataEraser($doctrine, $registry, new MfaOwnerResolver()),
        ];

        foreach ($erasers as $eraser) {
            self::assertInstanceOf(PersonalDataEraserInterface::class, $eraser);
            self::assertFalse($eraser->supports(new stdClass()));
            $eraser->erase(new ErasureRequest(new stdClass(), null, []));
        }
    }
}
