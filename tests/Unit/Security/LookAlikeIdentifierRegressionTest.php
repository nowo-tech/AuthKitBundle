<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Security;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginNotificationContext;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginNotifierInterface;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginRequestHandler;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginUserResolver;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotificationContext;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotifierInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetRequestHandler;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenResult;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetUserResolver;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\LoginLink\LoginLinkDetails;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * SEC-004 H1 regression: the database collation (MySQL utf8mb4_0900_ai_ci, MariaDB *_unicode_ci) is accent- and
 * case-insensitive, so the repository returns the victim for a look-alike address. The lookup must end as "not
 * found" and notifiers must only ever receive the address stored on the account.
 *
 * The repository stub plays the database: it returns the victim for every input, like such a collation would.
 */
final class LookAlikeIdentifierRegressionTest extends TestCase
{
    private const VICTIM = 'victim@example.com';

    /**
     * @return iterable<string, array{string}>
     */
    public static function lookAlikes(): iterable
    {
        yield 'accented domain (IDN the attacker owns)' => ["victim@ex\u{00E1}mple.com"];
        yield 'accented local part' => ["v\u{00ED}ctim@example.com"];
        yield 'decomposed accent' => ["victim@exa\u{0301}mple.com"];
        yield 'trailing space (PAD SPACE collations)' => ['victim@example.com '];
    }

    #[DataProvider('lookAlikes')]
    public function testMagicLoginLookAlikeIsNotFoundAndNothingIsSent(string $input): void
    {
        $notifier = $this->createMock(MagicLoginNotifierInterface::class);
        $notifier->expects(self::never())->method('notify');
        $handler = $this->createMock(LoginLinkHandlerInterface::class);
        $handler->expects(self::never())->method('createLoginLink');

        $this->magicHandler($notifier, $handler)->handle($input);
    }

    public function testMagicLoginNotifiesTheStoredAddressNotTheTypedOne(): void
    {
        $handler = $this->createMock(LoginLinkHandlerInterface::class);
        $handler->method('createLoginLink')->willReturn(new LoginLinkDetails('https://example.test/check', new DateTimeImmutable('+10 minutes')));

        $received = null;
        $notifier = $this->createMock(MagicLoginNotifierInterface::class);
        $notifier->expects(self::once())->method('notify')->willReturnCallback(
            static function (MagicLoginNotificationContext $context) use (&$received): void {
                $received = $context;
            },
        );

        $this->magicHandler($notifier, $handler)->handle('VICTIM@Example.COM');

        self::assertInstanceOf(MagicLoginNotificationContext::class, $received);
        self::assertSame(self::VICTIM, $received->identifier);
        self::assertSame('v***@example.com', $received->maskedIdentifier);
        self::assertInstanceOf(TestUser::class, $received->user);
    }

    #[DataProvider('lookAlikes')]
    public function testPasswordResetLookAlikeIsNotFoundAndNothingIsSent(string $input): void
    {
        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->expects(self::never())->method('createForUser');
        $notifier = $this->createMock(PasswordResetNotifierInterface::class);
        $notifier->expects(self::never())->method('notify');

        self::assertFalse($this->resetHandler($tokenManager, $notifier)->handle($input)->throttled);
    }

    public function testPasswordResetNotifiesTheStoredAddressNotTheTypedOne(): void
    {
        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->method('createForUser')->willReturnCallback(
            static fn (object $user): PasswordResetTokenResult => new PasswordResetTokenResult($user, 'token', new DateTimeImmutable('+1 hour'), PasswordResetDeliveryMode::Link),
        );

        $received = null;
        $notifier = $this->createMock(PasswordResetNotifierInterface::class);
        $notifier->expects(self::once())->method('notify')->willReturnCallback(
            static function (PasswordResetTokenResult $token, PasswordResetNotificationContext $context) use (&$received): void {
                $received = $context;
            },
        );

        $this->resetHandler($tokenManager, $notifier)->handle('Victim@EXAMPLE.com');

        self::assertInstanceOf(PasswordResetNotificationContext::class, $received);
        self::assertSame(self::VICTIM, $received->identifier);
        self::assertSame('v***@example.com', $received->maskedIdentifier);
        self::assertInstanceOf(TestUser::class, $received->user);
    }

    private function collationInsensitiveDatabase(): EntityManagerInterface
    {
        $victim = new TestUser(self::VICTIM);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([$victim]);
        $repository->method('findOneBy')->willReturn($victim);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        return $entityManager;
    }

    private function registry(): ProfileRegistry
    {
        return ProfileRegistryFactory::single(TestUser::class, [
            'magic_login'    => ['mode' => 'enabled', 'lifetime' => 600, 'max_uses' => 1],
            'password_reset' => ['delivery' => 'link'],
        ]);
    }

    private function magicHandler(MagicLoginNotifierInterface $notifier, LoginLinkHandlerInterface $handler): MagicLoginRequestHandler
    {
        return new MagicLoginRequestHandler(
            new MagicLoginUserResolver($this->collationInsensitiveDatabase(), $this->registry()),
            $notifier,
            $this->createMock(EventDispatcherInterface::class),
            $this->registry(),
            new RequestStack(),
            new AuthKitAttemptLimiter(new ArrayAdapter()),
            $handler,
        );
    }

    private function resetHandler(PasswordResetTokenManagerInterface $tokenManager, PasswordResetNotifierInterface $notifier): PasswordResetRequestHandler
    {
        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('https://example.test/reset');

        return new PasswordResetRequestHandler(
            new PasswordResetUserResolver($this->collationInsensitiveDatabase(), $this->registry()),
            $tokenManager,
            $notifier,
            AuthKitTestUrlGenerator::fromMock($inner),
            $this->createMock(EventDispatcherInterface::class),
            $this->registry(),
            new AuthKitAttemptLimiter(new ArrayAdapter()),
            new RequestStack(),
        );
    }
}
