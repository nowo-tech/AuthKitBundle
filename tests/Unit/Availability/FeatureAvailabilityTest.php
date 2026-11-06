<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Availability;

use Nowo\AuthKitBundle\Availability\FeatureAvailability;
use Nowo\AuthKitBundle\Availability\FeatureAvailabilityCheckerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class FeatureAvailabilityTest extends TestCase
{
    public function testNoCheckerMeansNoOpinion(): void
    {
        $availability = new FeatureAvailability();

        self::assertNull($availability->decision(FeatureAvailability::MAGIC_LOGIN, 'default'));
        self::assertFalse($availability->isUnavailable(FeatureAvailability::MAGIC_LOGIN, 'default'));
    }

    public function testCheckerIsAskedPerFeatureAndProfile(): void
    {
        $checker = $this->createMock(FeatureAvailabilityCheckerInterface::class);
        $checker->expects(self::once())->method('isAvailable')->with('admin')->willReturn(false);
        $availability = new FeatureAvailability(new ServiceLocator([
            'magic_login.admin' => static fn (): FeatureAvailabilityCheckerInterface => $checker,
        ]));

        self::assertNull($availability->decision(FeatureAvailability::MAGIC_LOGIN, 'default'));
        self::assertNull($availability->decision(FeatureAvailability::PASSWORD_RESET, 'admin'));
        self::assertTrue($availability->isUnavailable(FeatureAvailability::MAGIC_LOGIN, 'admin'));
    }

    public function testCheckerAnswerTrueIsReturned(): void
    {
        $checker = $this->createStub(FeatureAvailabilityCheckerInterface::class);
        $checker->method('isAvailable')->willReturn(true);
        $availability = new FeatureAvailability(new ServiceLocator([
            'password_reset.default' => static fn (): FeatureAvailabilityCheckerInterface => $checker,
        ]));

        self::assertTrue($availability->decision(FeatureAvailability::PASSWORD_RESET, 'default'));
        self::assertFalse($availability->isUnavailable(FeatureAvailability::PASSWORD_RESET, 'default'));
    }

    public function testThrowingCheckerFailsClosedAndLogsOnlyTheClass(): void
    {
        $checker = $this->createStub(FeatureAvailabilityCheckerInterface::class);
        $checker->method('isAvailable')->willThrowException(new RuntimeException('smtp://user:placeholder@mail.example'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            self::stringContains('treated as unavailable'),
            ['feature' => 'password_reset', 'profile' => 'default', 'exception' => RuntimeException::class],
        );
        $availability = new FeatureAvailability(new ServiceLocator([
            'password_reset.default' => static fn (): FeatureAvailabilityCheckerInterface => $checker,
        ]), $logger);

        self::assertFalse($availability->decision(FeatureAvailability::PASSWORD_RESET, 'default'));
    }
}
