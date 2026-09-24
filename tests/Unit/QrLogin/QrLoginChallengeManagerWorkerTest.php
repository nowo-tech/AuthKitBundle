<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\QrLogin;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Doctrine\EntityManagerRecovery;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Enum\QrLoginChallengeStatus;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeConflictException;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Nowo\AuthKitBundle\Repository\QrLoginChallengeRepository;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Two long-running workers, each with its own identity map, share one database row and are never reset.
 */
final class QrLoginChallengeManagerWorkerTest extends TestCase
{
    /** @var array{status: string} */
    private array $database = ['status' => 'pending'];

    public function testPollSeesApprovalMadeByAnotherWorker(): void
    {
        $desktopCopy = $this->challenge();
        $phoneCopy   = $this->challenge();
        $desktop     = $this->manager($desktopCopy);
        $phone       = $this->manager($phoneCopy);

        // Request 1 (desktop worker): poll loads the challenge into its identity map.
        $polled = $desktop->find('qr-1');
        self::assertNotNull($polled);
        self::assertSame(QrLoginChallengeStatus::Pending, $polled->getStatus());

        // Request 2 (phone worker): approval.
        $phone->approve($phoneCopy, new TestUser(), null);

        // Request 3 (desktop worker, same instances, no reset): the poll must see the approval.
        $polled = $desktop->find('qr-1');
        self::assertNotNull($polled);
        self::assertSame(QrLoginChallengeStatus::Approved, $polled->getStatus());
    }

    public function testChallengeCanOnlyBeConsumedOnceAcrossWorkers(): void
    {
        $this->database['status'] = 'approved';
        $firstCopy                = $this->challenge('approved');
        $secondCopy               = $this->challenge('approved');
        $user                     = new TestUser();

        $this->manager($firstCopy)->consume($firstCopy, $user);
        self::assertSame('consumed', $this->database['status']);

        $this->expectException(QrLoginChallengeConflictException::class);
        $this->manager($secondCopy)->consume($secondCopy, $user);
    }

    public function testApproveAndDenyRaceHasOneWinner(): void
    {
        $approveCopy = $this->challenge();
        $denyCopy    = $this->challenge();

        $this->manager($denyCopy)->deny($denyCopy);

        try {
            $this->manager($approveCopy)->approve($approveCopy, new TestUser(), null);
            self::fail('Approval of a denied challenge must fail.');
        } catch (QrLoginChallengeConflictException $exception) {
            self::assertStringContainsString('"qr-1"', $exception->getMessage());
        }

        self::assertSame('denied', $this->database['status']);
    }

    public function testExpiryCheckDoesNotOverwriteApprovalFromAnotherWorker(): void
    {
        $this->database['status'] = 'approved';
        $staleCopy                = $this->challenge('pending', '-1 second');

        self::assertFalse($this->manager($staleCopy)->isExpiredOrInvalid($staleCopy));
        self::assertSame('approved', $this->database['status']);
        self::assertSame(QrLoginChallengeStatus::Approved, $staleCopy->getStatus());
    }

    public function testFailedWriteResetsClosedEntityManager(): void
    {
        $challenge  = $this->challenge();
        $repository = $this->createMock(QrLoginChallengeRepository::class);
        $repository->method('transitionStatus')->willReturn(true);
        $repository->method('save')->willThrowException(new RuntimeException('flush failed'));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(false);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn(['default' => $entityManager]);
        $registry->expects(self::once())->method('resetManager')->with('default');

        $manager = new QrLoginChallengeManager(
            $repository,
            ProfileRegistryFactory::single(TestUser::class),
            $this->createMock(EventDispatcherInterface::class),
            'secret',
            new EntityManagerRecovery($registry),
        );

        $this->expectExceptionMessage('flush failed');
        $manager->deny($challenge);
    }

    private function manager(QrLoginChallenge $identityMapCopy): QrLoginChallengeManager
    {
        $repository = $this->createMock(QrLoginChallengeRepository::class);
        $repository->method('findFresh')->willReturnCallback(function () use ($identityMapCopy): QrLoginChallenge {
            $identityMapCopy->setStatus(QrLoginChallengeStatus::from($this->database['status']));

            return $identityMapCopy;
        });
        $repository->method('transitionStatus')->willReturnCallback(
            function (QrLoginChallenge $challenge, QrLoginChallengeStatus $from, QrLoginChallengeStatus $to): bool {
                if ($this->database['status'] !== $from->value) {
                    return false;
                }
                $this->database['status'] = $to->value;

                return true;
            },
        );
        $repository->method('save')->willReturnCallback(function (QrLoginChallenge $challenge): void {
            $this->database['status'] = $challenge->getStatus()->value;
        });

        return new QrLoginChallengeManager(
            $repository,
            ProfileRegistryFactory::single(TestUser::class),
            $this->createMock(EventDispatcherInterface::class),
            'secret',
        );
    }

    private function challenge(string $status = 'pending', string $expires = '+60 seconds'): QrLoginChallenge
    {
        $challenge = new QrLoginChallenge(
            'qr-1',
            'QRCODE01',
            hash('sha256', 'cookie'),
            hash_hmac('sha256', '127.0.0.1', 'secret'),
            hash_hmac('sha256', 'UA', 'secret'),
            'Chrome',
            hash('sha256', 'token'),
            new DateTimeImmutable($expires),
        );

        return $challenge->setStatus(QrLoginChallengeStatus::from($status));
    }
}
