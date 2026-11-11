<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\PersonalData;

use InvalidArgumentException;
use Nowo\AuthKitBundle\PersonalData\InvitationErasureResult;
use Nowo\AuthKitBundle\PersonalData\InvitationPersonalDataSubject;
use Nowo\AuthKitBundle\PersonalData\SocialAccountPersonalDataSubject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

final class PersonalDataSubjectsTest extends TestCase
{
    public function testAnEmailIsNormalisedAsTheInvitationStoresIt(): void
    {
        $subject = InvitationPersonalDataSubject::fromEmail("  José@Example.COM\t");

        self::assertSame(InvitationPersonalDataSubject::KIND_EMAIL, $subject->kind);
        self::assertSame('josé@example.com', $subject->value);
        self::assertTrue($subject->isEmail());
        self::assertSame("email\0josé@example.com", $subject->key());
    }

    public function testAnIdentifierIsKeptAsGivenAndCutLikeTheColumn(): void
    {
        $subject = InvitationPersonalDataSubject::fromUserIdentifier(' UserA ');

        self::assertSame(InvitationPersonalDataSubject::KIND_USER_IDENTIFIER, $subject->kind);
        self::assertSame(' UserA ', $subject->value);
        self::assertFalse($subject->isEmail());
        self::assertSame(str_repeat('a', 180), InvitationPersonalDataSubject::fromUserIdentifier(str_repeat('a', 200))->value);
    }

    /** @return iterable<string, array{callable(): mixed}> */
    public static function invalidSubjects(): iterable
    {
        yield 'empty e-mail' => [static fn (): InvitationPersonalDataSubject => InvitationPersonalDataSubject::fromEmail('   ')];
        yield 'NUL e-mail' => [static fn (): InvitationPersonalDataSubject => InvitationPersonalDataSubject::fromEmail("a\0@example.com")];
        yield 'long e-mail' => [static fn (): InvitationPersonalDataSubject => InvitationPersonalDataSubject::fromEmail(str_repeat('a', 181))];
        yield 'empty identifier' => [static fn (): InvitationPersonalDataSubject => InvitationPersonalDataSubject::fromUserIdentifier('')];
        yield 'NUL identifier' => [static fn (): InvitationPersonalDataSubject => InvitationPersonalDataSubject::fromUserIdentifier("a\0b")];
        yield 'empty class' => [static fn (): SocialAccountPersonalDataSubject => SocialAccountPersonalDataSubject::fromUserId('', '1')];
        yield 'long user id' => [static fn (): SocialAccountPersonalDataSubject => SocialAccountPersonalDataSubject::fromUserId('App\\User', str_repeat('1', 65))];
        yield 'NUL legacy' => [static fn (): SocialAccountPersonalDataSubject => SocialAccountPersonalDataSubject::fromLegacyIdentifier('App\\User', "a\0")];
        yield 'long legacy' => [static fn (): SocialAccountPersonalDataSubject => SocialAccountPersonalDataSubject::fromLegacyIdentifier('App\\User', str_repeat('a', 256))];
    }

    #[DataProvider('invalidSubjects')]
    public function testInvalidValuesAreRejected(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);
        $factory();
    }

    public function testSocialSubjects(): void
    {
        $byId   = SocialAccountPersonalDataSubject::fromUserId('App\\User', ' 7');
        $legacy = SocialAccountPersonalDataSubject::fromLegacyIdentifier('App\\User', str_repeat('a', 255));

        self::assertSame(['App\\User', ' 7', false], [$byId->userClass, $byId->value, $byId->legacy]);
        self::assertTrue($legacy->legacy);
    }

    public function testErasureResult(): void
    {
        $result = (new InvitationErasureResult(1, 2, 3))->with(new InvitationErasureResult(revoked: 1));

        self::assertSame(['revoked' => 2, 'deleted' => 2, 'references_cleared' => 3], $result->toArray());
        self::assertSame(7, $result->total());
        self::assertSame(0, (new InvitationErasureResult())->total());
    }
}
