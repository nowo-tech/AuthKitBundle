<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\AuthKitBundle\Controller\QrLoginCompleteController;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Enum\QrLoginChallengeStatus;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Nowo\AuthKitBundle\QrLogin\QrLoginGate;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class QrLoginCompleteControllerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function overrides(): array
    {
        return [
            'qr_login' => ['mode' => 'enabled'],
        ];
    }

    private function approvedChallenge(?string $userClass = TestUser::class, ?string $userId = 'user@test.com'): QrLoginChallenge
    {
        $challenge = new QrLoginChallenge(
            'complete-id',
            'COMP1234',
            hash('sha256', 'cookie'),
            hash_hmac('sha256', '127.0.0.1', 'secret'),
            hash_hmac('sha256', 'UA', 'secret'),
            'Chrome · Windows',
            hash('sha256', 'token'),
            new DateTimeImmutable('+90 seconds'),
        );
        if ($userClass !== null && $userId !== null) {
            $challenge->markApproved($userClass, $userId, '+34 *** 22');
        }

        return $challenge;
    }

    /**
     * @param array<string, mixed> $qr
     */
    private function controller(
        QrLoginChallengeManager $manager,
        ?EntityManagerInterface $em = null,
        ?Security $security = null,
        array $qr = [],
    ): QrLoginCompleteController {
        $overrides = ['qr_login' => array_merge(['mode' => 'enabled'], $qr)];
        $inner     = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/login');

        return new QrLoginCompleteController(
            new QrLoginGate(ProfileRegistryFactory::single(TestUser::class, $overrides)),
            $manager,
            $em ?? $this->createMock(EntityManagerInterface::class),
            $security ?? $this->createMock(Security::class),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, $overrides),
        );
    }

    public function testRedirectsWhenDisabled(): void
    {
        $response = $this->controller($this->createMock(QrLoginChallengeManager::class), null, null, ['mode' => 'disabled'])
            ->complete(new Request(), 'complete-id');
        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/login', $response->headers->get('Location'));
    }

    public function testRedirectsWhenChallengeMissing(): void
    {
        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn(null);
        self::assertSame('/login', $this->controller($manager)->complete(new Request(), 'x')->headers->get('Location'));
    }

    public function testRedirectsWhenCookieInvalid(): void
    {
        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn($this->approvedChallenge());
        $manager->method('verifyDesktopCookie')->willReturn(false);
        self::assertSame('/login', $this->controller($manager)->complete(new Request(), 'complete-id')->headers->get('Location'));
    }

    public function testRedirectsWhenBindingFails(): void
    {
        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn($this->approvedChallenge());
        $manager->method('verifyDesktopCookie')->willReturn(true);
        $manager->method('verifyDesktopBinding')->willReturn(false);
        self::assertSame('/login', $this->controller($manager)->complete(new Request(), 'complete-id')->headers->get('Location'));
    }

    public function testRedirectsWhenNotApproved(): void
    {
        $pending = new QrLoginChallenge(
            'complete-id',
            'COMP1234',
            hash('sha256', 'cookie'),
            hash_hmac('sha256', '127.0.0.1', 'secret'),
            hash_hmac('sha256', 'UA', 'secret'),
            'Chrome',
            hash('sha256', 'token'),
            new DateTimeImmutable('+90 seconds'),
        );
        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn($pending);
        $manager->method('verifyDesktopCookie')->willReturn(true);
        $manager->method('verifyDesktopBinding')->willReturn(true);
        self::assertSame('/login', $this->controller($manager)->complete(new Request(), 'complete-id')->headers->get('Location'));
    }

    public function testRedirectsWhenUserMissingFromChallenge(): void
    {
        $challenge = $this->approvedChallenge();
        // Force null user fields after approve by constructing pending and not approving — use reflection-free path:
        // markApproved always sets fields; instead create approved then we can't null them easily.
        // Cover branch via a challenge that was approved with empty user id impossible — use mock status Approved
        // with getters returning null by creating challenge and calling markApproved then... can't.
        // Use a custom subclass via anonymous? Entity is final? Not final.
        $challenge = new class('complete-id', 'COMP1234', hash('sha256', 'cookie'), hash_hmac('sha256', '127.0.0.1', 'secret'), hash_hmac('sha256', 'UA', 'secret'), 'Chrome', hash('sha256', 'token'), new DateTimeImmutable('+90 seconds')) extends QrLoginChallenge {
            public function getStatus(): QrLoginChallengeStatus
            {
                return QrLoginChallengeStatus::Approved;
            }

            public function getUserClass(): ?string
            {
                return null;
            }

            public function getUserId(): ?string
            {
                return null;
            }
        };

        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn($challenge);
        $manager->method('verifyDesktopCookie')->willReturn(true);
        $manager->method('verifyDesktopBinding')->willReturn(true);

        self::assertSame('/login', $this->controller($manager)->complete(new Request(), 'complete-id')->headers->get('Location'));
    }

    public function testRedirectsWhenUserNotFoundInRepository(): void
    {
        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn($this->approvedChallenge());
        $manager->method('verifyDesktopCookie')->willReturn(true);
        $manager->method('verifyDesktopBinding')->willReturn(true);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        self::assertSame('/login', $this->controller($manager, $em)->complete(new Request(), 'complete-id')->headers->get('Location'));
    }

    public function testLogsInAndRedirectsOnSuccess(): void
    {
        $user = new TestUser();
        $user->setEmail('user@test.com');
        $challenge = $this->approvedChallenge();

        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn($challenge);
        $manager->method('verifyDesktopCookie')->willReturn(true);
        $manager->method('verifyDesktopBinding')->willReturn(true);
        $manager->expects(self::once())->method('consume')->with($challenge, $user, 'default');

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($user);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $security = $this->createMock(Security::class);
        $security->expects(self::once())->method('login')->with($user, null, 'main');

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/dashboard');

        $controller = new QrLoginCompleteController(
            new QrLoginGate(ProfileRegistryFactory::single(TestUser::class, $this->overrides())),
            $manager,
            $em,
            $security,
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, $this->overrides()),
        );

        $response = $controller->complete(new Request(), 'complete-id');
        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/dashboard', $response->headers->get('Location'));
    }
}
