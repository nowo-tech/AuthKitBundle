<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Security;

use Doctrine\Persistence\ObjectRepository;
use Nowo\AuthKitBundle\Security\UserIdentifierMatcher;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use stdClass;

final class UserIdentifierMatcherTest extends TestCase
{
    public function testPicksTheCandidateWhoseStoredIdentifierEqualsTheInput(): void
    {
        $lookAlike = new TestUser("ana@ex\u{00E1}mple.com");
        $exact     = new TestUser('Ana@Example.com');

        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects(self::once())->method('findBy')
            ->with(['email' => 'ana@example.com'], null, UserIdentifierMatcher::MAX_CANDIDATES)
            ->willReturn([$lookAlike, $exact]);

        self::assertSame($exact, (new UserIdentifierMatcher())->find($repository, 'email', 'ana@example.com'));
    }

    public function testComposedAndDecomposedFormsAreEqual(): void
    {
        $user = new TestUser("jos\u{00E9}@example.com");

        self::assertTrue((new UserIdentifierMatcher())->matches($user, 'email', "JOSE\u{0301}@example.com"));
        self::assertFalse((new UserIdentifierMatcher())->matches($user, 'email', 'jose@example.com'));
    }

    public function testEmptyInputUnreadableOrEmptyStoredValueNeverMatch(): void
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects(self::never())->method('findBy');
        $matcher = new UserIdentifierMatcher();

        self::assertNull($matcher->find($repository, 'email', ''));
        self::assertNull($matcher->storedIdentifier(new stdClass(), 'email'));
        self::assertNull($matcher->storedIdentifier(new TestUser(''), 'email'));
        self::assertFalse($matcher->matches(new stdClass(), 'email', 'a@b.c'));
    }

    public function testNormalizeLowercasesWithoutTrimming(): void
    {
        self::assertSame("\u{00E9}t\u{00E9} ", UserIdentifierMatcher::normalize("E\u{0301}T\u{00C9} "));
    }
}
