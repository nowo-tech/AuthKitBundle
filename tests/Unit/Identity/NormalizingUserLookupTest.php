<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Identity;

use Nowo\AuthKitBundle\Identity\ExactIdentifierNormalizer;
use Nowo\AuthKitBundle\Identity\LowercaseAsciiEmailIdentifierNormalizer;
use Nowo\AuthKitBundle\Identity\NormalizingUserLookup;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\User\UserLookupInterface;
use PHPUnit\Framework\TestCase;

/**
 * 1.48.0: the outermost decorator of the user lookup port hands every adapter the normalised identifier and sends no
 * lookup for a refused one.
 */
final class NormalizingUserLookupTest extends TestCase
{
    public function testTheInnerLookupReceivesTheNormalisedIdentifier(): void
    {
        $profile = $this->profile();
        $user    = new TestUser('bob@example.com');
        $inner   = $this->createMock(UserLookupInterface::class);
        $inner->expects(self::once())->method('findByIdentifier')->with($profile, 'bob@example.com')->willReturn($user);

        self::assertSame($user, (new NormalizingUserLookup($inner, new LowercaseAsciiEmailIdentifierNormalizer()))->findByIdentifier($profile, 'Bob@Example.COM'));
    }

    public function testTheDefaultNormalizerPassesTheTypedValue(): void
    {
        $profile = $this->profile();
        $inner   = $this->createMock(UserLookupInterface::class);
        $inner->expects(self::once())->method('findByIdentifier')->with($profile, 'Bob@Example.COM')->willReturn(null);

        self::assertNull((new NormalizingUserLookup($inner, new ExactIdentifierNormalizer()))->findByIdentifier($profile, 'Bob@Example.COM'));
    }

    public function testARefusedOrEmptyIdentifierReachesNoLookup(): void
    {
        $inner = $this->createMock(UserLookupInterface::class);
        $inner->expects(self::never())->method('findByIdentifier');
        $lookup = new NormalizingUserLookup($inner, new ExactIdentifierNormalizer());

        self::assertNull($lookup->findByIdentifier($this->profile(), "bob\u{200B}@example.com"));
        self::assertNull($lookup->findByIdentifier($this->profile(), ''));
    }

    public function testTheOtherMethodsAreDelegatedUnchanged(): void
    {
        $profile = $this->profile();
        $user    = new TestUser('Bob@Example.com');
        $inner   = $this->createMock(UserLookupInterface::class);
        $inner->expects(self::once())->method('findById')->with($profile, '42')->willReturn($user);
        $inner->expects(self::once())->method('countUsers')->with($profile)->willReturn(3);
        $inner->expects(self::once())->method('hasCaseVariant')->with($profile, 'email', 'Bob@Example.com')->willReturn(true);
        $lookup = new NormalizingUserLookup($inner, new LowercaseAsciiEmailIdentifierNormalizer());

        self::assertSame($user, $lookup->findById($profile, '42'));
        self::assertSame(3, $lookup->countUsers($profile));
        self::assertTrue($lookup->hasCaseVariant($profile, 'email', 'Bob@Example.com'));
    }

    private function profile(): ProfileSettings
    {
        return ProfileRegistryFactory::single(TestUser::class)->getDefault();
    }
}
