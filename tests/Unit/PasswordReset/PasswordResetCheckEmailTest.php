<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\PasswordReset;

use DateTimeImmutable;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetCheckEmail;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetRequestOutcome;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetThrottledException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class PasswordResetCheckEmailTest extends TestCase
{
    public function testRoundTripsThroughTheSession(): void
    {
        $request = $this->requestWithSession();
        $at      = new DateTimeImmutable('@1792760000');

        (new PasswordResetCheckEmail('operator', 3600, true, $at))->store($request);
        $read = PasswordResetCheckEmail::fromRequest($request);

        self::assertNotNull($read);
        self::assertSame('operator', $read->profile);
        self::assertSame(3600, $read->tokenTtl);
        self::assertTrue($read->throttled);
        self::assertSame(1792760000, $read->availableAt?->getTimestamp());
    }

    public function testWithoutAvailableAt(): void
    {
        $request = $this->requestWithSession();

        (new PasswordResetCheckEmail('default', 900))->store($request);
        $read = PasswordResetCheckEmail::fromRequest($request);

        self::assertNotNull($read);
        self::assertFalse($read->throttled);
        self::assertNull($read->availableAt);
    }

    public function testNullWithoutSessionOrPayload(): void
    {
        $request = new Request();
        (new PasswordResetCheckEmail('default', 900))->store($request);
        self::assertNull(PasswordResetCheckEmail::fromRequest($request));

        $request = $this->requestWithSession();
        self::assertNull(PasswordResetCheckEmail::fromRequest($request));

        $request->getSession()->set(PasswordResetCheckEmail::SESSION_KEY, ['profile' => 'x', 'token_ttl' => '60']);
        self::assertNull(PasswordResetCheckEmail::fromRequest($request));
    }

    public function testThrottledExceptionAndOutcome(): void
    {
        $previous  = new RuntimeException('upstream');
        $at        = new DateTimeImmutable('+5 minutes');
        $exception = new PasswordResetThrottledException($at, 'too soon', $previous);

        self::assertSame($at, $exception->getAvailableAt());
        self::assertSame('too soon', $exception->getMessage());
        self::assertSame($previous, $exception->getPrevious());
        self::assertNull((new PasswordResetThrottledException())->getAvailableAt());

        $outcome = PasswordResetRequestOutcome::throttled($exception);
        self::assertTrue($outcome->throttled);
        self::assertSame($at, $outcome->availableAt);

        $accepted = PasswordResetRequestOutcome::accepted();
        self::assertFalse($accepted->throttled);
        self::assertNull($accepted->availableAt);
    }

    private function requestWithSession(): Request
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }
}
