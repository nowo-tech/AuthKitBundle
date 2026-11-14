<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Security;

use Doctrine\Persistence\ObjectRepository;
use Nowo\AuthKitBundle\Identity\LowercaseAsciiEmailIdentifierNormalizer;
use Nowo\AuthKitBundle\Identity\UserIdentifierNormalizerInterface;
use Nowo\AuthKitBundle\Security\UserIdentifierMatcher;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * 1.49.0: the matcher queries and re-checks the identifier the configured normaliser returns, and stores that form on
 * the accounts AuthKit creates.
 */
final class UserIdentifierMatcherNormalizerTest extends TestCase
{
    public function testTheQueryUsesTheNormalisedIdentifier(): void
    {
        $stored     = new TestUser('bob@example.com');
        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects(self::once())->method('findBy')
            ->with(['email' => 'bob@example.com'], null, UserIdentifierMatcher::MAX_CANDIDATES)
            ->willReturn([$stored]);

        self::assertSame($stored, $this->lowercase()->find($repository, 'email', 'Bob@Example.COM'));
    }

    public function testTheDefaultQueriesTheTypedValueAndReChecksCaseInsensitively(): void
    {
        // Before 1.49.0 and with the default: a folding column returns the stored `bob@...`, the re-check accepts it.
        $stored     = new TestUser('bob@example.com');
        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects(self::once())->method('findBy')
            ->with(['email' => 'Bob@Example.COM'], null, UserIdentifierMatcher::MAX_CANDIDATES)
            ->willReturn([$stored]);

        self::assertSame($stored, (new UserIdentifierMatcher())->find($repository, 'email', 'Bob@Example.COM'));
        self::assertSame('Bob@Example.COM', (new UserIdentifierMatcher())->normalizeInput('Bob@Example.COM'));
    }

    public function testARefusedIdentifierIsNeverQueriedNorMatched(): void
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects(self::never())->method('findBy');
        $matcher = new UserIdentifierMatcher();
        $user    = new TestUser("bob\u{200B}@example.com");

        self::assertNull($matcher->find($repository, 'email', "bob\u{200B}@example.com"));
        self::assertNull($matcher->normalizeInput(''));
        self::assertFalse($matcher->matches($user, 'email', "bob\u{200B}@example.com"));
        self::assertFalse($matcher->matchesUser($user, 'email', "bob\u{200B}@example.com"));
    }

    public function testTheReCheckComparesTheStoredValueWithTheNormalisedInput(): void
    {
        $normalizer = new class implements UserIdentifierNormalizerInterface {
            public function normalize(string $raw): string
            {
                return $raw === 'alias' ? 'bob@example.com' : $raw;
            }
        };
        $matcher = new UserIdentifierMatcher(null, $normalizer);
        $user    = new TestUser('bob@example.com');

        self::assertTrue($matcher->matches($user, 'email', 'alias'));
        self::assertTrue($matcher->matchesUser($user, 'email', 'alias'));
        self::assertFalse($matcher->matchesUser($user, 'email', 'other@example.com'));
    }

    public function testAccountsAuthKitCreatesStoreTheNormalisedForm(): void
    {
        $user = new TestUser('Bob@Example.COM');
        $this->lowercase()->normalizeStored($user, 'email');
        self::assertSame('bob@example.com', $user->getEmail());

        $kept = new TestUser('Bob@Example.COM');
        (new UserIdentifierMatcher())->normalizeStored($kept, 'email');
        self::assertSame('Bob@Example.COM', $kept->getEmail(), 'The default stores the value as typed.');

        $refused = new TestUser('Bob@Example.COM ');
        $this->lowercase()->normalizeStored($refused, 'email');
        self::assertSame('Bob@Example.COM ', $refused->getEmail(), 'A refused value is left to the validation of the host.');

        $empty = new TestUser('');
        $this->lowercase()->normalizeStored($empty, 'email');
        self::assertSame('', $empty->getEmail());

        $readOnly = new class {
            public function getEmail(): string
            {
                return 'Bob@Example.COM';
            }
        };
        $this->lowercase()->normalizeStored($readOnly, 'email');
        self::assertSame('Bob@Example.COM', $readOnly->getEmail());

        $this->lowercase()->normalizeStored(new stdClass(), 'email');
        $this->addToAssertionCount(1);
    }

    private function lowercase(): UserIdentifierMatcher
    {
        return new UserIdentifierMatcher(null, new LowercaseAsciiEmailIdentifierNormalizer());
    }
}
