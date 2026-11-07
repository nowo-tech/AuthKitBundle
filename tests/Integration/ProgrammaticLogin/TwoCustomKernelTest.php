<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\ProgrammaticLogin;

use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Enum\QrLoginChallengeStatus;

/**
 * Two host authenticators: AuthKit does not guess, cannot sign in, and so writes nothing (GY-L9).
 */
final class TwoCustomKernelTest extends ProgrammaticLoginKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TwoCustomKernel::class;
    }

    public function testSocialRefusesBeforeLinking(): void
    {
        $this->socialCallback();

        self::assertResponseRedirects('/login');
        self::assertNull($this->token());
        $this->assertAdaKeepsHerPasswordAndHasNoLink();
    }

    public function testQrRefusesBeforeConsumingTheChallenge(): void
    {
        $this->qrCompletion();

        self::assertResponseRedirects('/login');
        self::assertNull($this->token());
        $table = $this->em()->getClassMetadata(QrLoginChallenge::class)->getTableName();
        self::assertSame(QrLoginChallengeStatus::Approved->value, $this->em()->getConnection()->fetchOne('SELECT status FROM ' . $table));
    }

    public function testRegistrationRefusesBeforeCreatingTheAccount(): void
    {
        $this->submitRegistration('/register', 'new@example.com');

        self::assertResponseRedirects('/login');
        self::assertSame(0, $this->userCount('new@example.com'));
    }

    public function testInvitationRefusesBeforeBeingConsumed(): void
    {
        $this->submitRegistration($this->invitationPath('invited@example.com'), 'invited@example.com');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->userCount('invited@example.com'));
        $invitation = $this->em()->getRepository(Invitation::class)->findOneBy(['email' => 'invited@example.com']);
        self::assertInstanceOf(Invitation::class, $invitation);
        self::assertNull($invitation->getAcceptedAt());
    }
}
