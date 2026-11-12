<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PasswordReset;

use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenResult;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Integration\PasswordReset\Entity\ResetUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

use function explode;
use function mb_strtoupper;
use function str_contains;

/**
 * 1.46.0 (REQ-ARCH-001 rule 2): `password_reset.storage: table` takes every reset decision exactly like the
 * user-entity storage. The same scenario runs against both storages (each delivery, a user with an id and a user
 * keyed by its identifier) and the outcomes must be identical: link and code resolution, wrong code, cross-user
 * tokens, a code tried as a link token, case variants, expiry (the second it expires and one after), a second request
 * replacing the first, single use after the reset, invalidation after a password or identifier change in a flush,
 * clearForUser() and the max_code_attempts budget (the credential is cleared once it is spent).
 */
final class ResetStorageEquivalenceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function cases(): iterable
    {
        foreach (['link', 'code', 'both'] as $delivery) {
            yield $delivery . ' / user with id' => [$delivery, ResetUser::class];
            yield $delivery . ' / user without id' => [$delivery, KernelUser::class];
        }
    }

    /**
     * @param class-string $userClass
     */
    #[DataProvider('cases')]
    public function testBothStoragesTakeTheSameDecisions(string $delivery, string $userClass): void
    {
        $userEntity = $this->scenario(new ResetStorageHarness('user_entity', $delivery, $userClass, ['max_code_attempts' => 3]));
        $table      = $this->scenario(new ResetStorageHarness('table', $delivery, $userClass, ['max_code_attempts' => 3]));

        self::assertSame($userEntity, $table);
        self::assertContains('link ok: ada', $userEntity, 'The scenario resolves at least one credential.');
    }

    /**
     * @param class-string $userClass
     */
    #[DataProvider('cases')]
    public function testTheTableNeverWritesTheUser(string $delivery, string $userClass): void
    {
        $h   = new ResetStorageHarness('table', $delivery, $userClass);
        $ada = $h->addUser('ada@example.com');
        $h->em->clear();
        $ada = $h->em->find($ada::class, $ada instanceof ResetUser ? $ada->getId() : 'ada@example.com');
        self::assertNotNull($ada);

        $result = $h->manager->createForUser($ada);
        self::assertSame([], $h->em->getUnitOfWork()->getScheduledEntityUpdates());
        $h->em->clear();
        $fresh = $h->em->find($ada::class, $ada instanceof ResetUser ? $ada->getId() : 'ada@example.com');
        self::assertNotNull($fresh);
        self::assertNull($fresh->getPasswordResetToken());
        self::assertNull($fresh->getPasswordResetExpiresAt());
        self::assertSame(1, $h->count('SELECT COUNT(*) FROM ' . $h->resetTable()));
        self::assertNotSame('', $result->plainToken);

        $h->manager->clearForUser($fresh);
        self::assertSame(0, $h->count('SELECT COUNT(*) FROM ' . $h->resetTable()));
    }

    /**
     * @return list<string>
     */
    private function scenario(ResetStorageHarness $h): array
    {
        $ada = $h->addUser('ada@example.com');
        $bob = $h->addUser('bob@example.com');
        $out = [];

        // Issue, resolve by link and by code; a wrong code; cross-user use; a code tried as a link token; case variant.
        $a1    = $h->manager->createForUser($ada);
        $b1    = $h->manager->createForUser($bob);
        $out[] = 'link ' . $this->who($h->manager->resolveUserByLinkToken($this->link($a1)));
        $out[] = 'link ' . $this->who($h->manager->resolveUserByLinkToken($this->link($b1)));
        $out[] = 'link wrong ' . $this->who($h->manager->resolveUserByLinkToken('not-a-token'));
        $out[] = 'code ' . $this->who($h->manager->resolveUserByIdentifierAndCode('ada@example.com', $this->code($a1)));
        $out[] = 'code upper ' . $this->who($h->manager->resolveUserByIdentifierAndCode(mb_strtoupper('ada@example.com'), $this->code($a1)));
        $out[] = 'code of bob for ada ' . $this->who($h->manager->resolveUserByIdentifierAndCode('ada@example.com', $this->code($b1)));
        $out[] = 'code unknown user ' . $this->who($h->manager->resolveUserByIdentifierAndCode('nobody@example.com', $this->code($a1)));
        $out[] = 'code as link ' . $this->who($h->manager->resolveUserByLinkToken($this->code($b1)));
        $out[] = 'code right ' . $this->who($h->manager->resolveUserByIdentifierAndCode('ada@example.com', $this->code($a1)));

        // A second request replaces the first.
        $a2    = $h->manager->createForUser($ada);
        $out[] = 'old link ' . $this->who($h->manager->resolveUserByLinkToken($this->link($a1)));
        $out[] = 'new link ' . $this->who($h->manager->resolveUserByLinkToken($this->link($a2)));
        $out[] = 'old code ' . $this->who($h->manager->resolveUserByIdentifierAndCode('ada@example.com', $this->code($a1)));
        $out[] = 'new code ' . $this->who($h->manager->resolveUserByIdentifierAndCode('ada@example.com', $this->code($a2)));

        // Expiry: valid at the last second, invalid one second later.
        $h->clock->sleep(3600);
        $out[] = 'link at expiry ' . $this->who($h->manager->resolveUserByLinkToken($this->link($a2)));
        $out[] = 'code at expiry ' . $this->who($h->manager->resolveUserByIdentifierAndCode('bob@example.com', $this->code($b1)));
        $h->clock->sleep(1);
        $out[] = 'link expired ' . $this->who($h->manager->resolveUserByLinkToken($this->link($a2)));
        $out[] = 'code expired ' . $this->who($h->manager->resolveUserByIdentifierAndCode('ada@example.com', $this->code($a2)));

        // Single use: the completion clears the credential.
        $a3 = $h->manager->createForUser($ada);
        $h->manager->clearForUser($ada);
        $out[] = 'link after clear ' . $this->who($h->manager->resolveUserByLinkToken($this->link($a3)));
        $out[] = 'code after clear ' . $this->who($h->manager->resolveUserByIdentifierAndCode('ada@example.com', $this->code($a3)));

        // A password change in a flush kills the pending credential (SEC-004 L6); another user's stays.
        $a4 = $h->manager->createForUser($ada);
        $b4 = $h->manager->createForUser($bob);
        $ada->setPassword('changed');
        $h->em->flush();
        $out[] = 'link after password change ' . $this->who($h->manager->resolveUserByLinkToken($this->link($a4)));
        $out[] = 'other user keeps ' . $this->who($h->manager->resolveUserByLinkToken($this->link($b4)));

        // max_code_attempts (3): the budget spent clears the credential, even for the right code afterwards.
        $a5 = $h->manager->createForUser($ada);
        for ($i = 0; $i < 3; ++$i) {
            $out[] = 'wrong ' . $i . ' ' . $this->who($h->manager->resolveUserByIdentifierAndCode('ada@example.com', $this->wrongCode($a5)));
        }
        $out[] = 'right after budget ' . $this->who($h->manager->resolveUserByIdentifierAndCode('ada@example.com', $this->code($a5)));
        $out[] = 'link after budget ' . $this->who($h->manager->resolveUserByLinkToken($this->link($a5)));

        // An identifier change in a flush kills the pending credential.
        $b6 = $h->manager->createForUser($bob);
        if ($bob instanceof ResetUser) {
            $bob->setEmail('robert@example.com');
            $h->em->flush();
            $out[] = 'link after identifier change ' . $this->who($h->manager->resolveUserByLinkToken($this->link($b6)));
        }

        return $out;
    }

    private function link(PasswordResetTokenResult $result): string
    {
        return $result->linkToken() ?? $result->plainToken;
    }

    private function code(PasswordResetTokenResult $result): string
    {
        return $result->code() ?? $result->plainToken;
    }

    private function wrongCode(PasswordResetTokenResult $result): string
    {
        return str_contains($result->plainToken, ':') ? explode(':', $result->plainToken)[0] . 'x' : $result->plainToken . 'x';
    }

    private function who(?object $user): string
    {
        return $user instanceof UserInterface ? 'ok: ' . explode('@', $user->getUserIdentifier())[0] : 'null';
    }
}
