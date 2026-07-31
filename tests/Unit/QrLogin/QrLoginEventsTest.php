<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\QrLogin;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\QrLogin\Event\QrLoginApprovedEvent;
use Nowo\AuthKitBundle\QrLogin\Event\QrLoginChallengeCreatedEvent;
use Nowo\AuthKitBundle\QrLogin\Event\QrLoginCompletedEvent;
use Nowo\AuthKitBundle\QrLogin\Event\QrLoginDeniedEvent;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class QrLoginEventsTest extends TestCase
{
    private function challenge(): QrLoginChallenge
    {
        return new QrLoginChallenge(
            'event-id',
            'EVNT1234',
            hash('sha256', 'cookie'),
            hash_hmac('sha256', '127.0.0.1', 'secret'),
            hash_hmac('sha256', 'UA', 'secret'),
            'Chrome',
            hash('sha256', 'token'),
            new DateTimeImmutable('+60 seconds'),
        );
    }

    public function testChallengeCreatedEventExposesChallengeAndProfile(): void
    {
        $challenge = $this->challenge();
        $event     = new QrLoginChallengeCreatedEvent($challenge, 'default');

        self::assertSame($challenge, $event->challenge);
        self::assertSame('default', $event->profileName);
    }

    public function testApprovedEventExposesUser(): void
    {
        $challenge = $this->challenge();
        $user      = new TestUser();
        $event     = new QrLoginApprovedEvent($challenge, $user, 'default');

        self::assertSame($user, $event->user);
        self::assertSame($challenge, $event->challenge);
    }

    public function testDeniedAndCompletedEvents(): void
    {
        $challenge = $this->challenge();
        $user      = new TestUser();

        $denied    = new QrLoginDeniedEvent($challenge, 'admin');
        $completed = new QrLoginCompletedEvent($challenge, $user, 'admin');

        self::assertSame('admin', $denied->profileName);
        self::assertSame($user, $completed->user);
        self::assertSame('admin', $completed->profileName);
    }
}
