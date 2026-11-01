<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Invitation;

use Nowo\AuthKitBundle\Command\PurgeInvitationsCommand;
use Nowo\AuthKitBundle\Repository\InvitationRepository;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Profiles with invitations disabled are skipped by the purge (nothing deleted, no event).
 */
final class PurgeInvitationsCommandTest extends TestCase
{
    public function testSkipsProfilesWithInvitationsDisabled(): void
    {
        $repository = $this->createMock(InvitationRepository::class);
        $repository->expects(self::never())->method('deleteClosedBefore');
        $tester = new CommandTester(new PurgeInvitationsCommand($repository, ProfileRegistryFactory::single(TestUser::class), new MockClock()));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Deleted 0 invitation(s).', $tester->getDisplay());
    }
}
