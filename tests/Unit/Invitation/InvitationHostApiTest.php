<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Invitation;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Enum\InvitationStatus;
use Nowo\AuthKitBundle\Invitation\Event\InvitationAcceptingEvent;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptance;
use Nowo\AuthKitBundle\Invitation\InvitationPayloadMerger;
use Nowo\AuthKitBundle\Invitation\InvitationRefusal;
use Nowo\AuthKitBundle\Invitation\InvitationRefusedException;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * 1.33 value objects: payload merge strategies, the pre-accept event, refusal reasons and the acceptance outcome.
 */
final class InvitationHostApiTest extends TestCase
{
    public function testReplaceMergeKeepsThe132Behaviour(): void
    {
        self::assertSame(['a' => ['y' => 2], 'b' => 1], InvitationPayloadMerger::merge('replace', ['a' => ['x' => 1], 'b' => 1], ['a' => ['y' => 2]]));
    }

    public function testDeepMergeMergesMapsAndUnionsLists(): void
    {
        $merged = InvitationPayloadMerger::merge(
            'deep',
            ['org' => 1, 'projects' => [1, 2], 'meta' => ['a' => 1, 'tags' => ['x']], 'empty' => [], 'scalar' => 'old'],
            ['projects' => [2, 3], 'meta' => ['b' => 2, 'tags' => ['y']], 'empty' => [5], 'scalar' => ['now' => 'array'], 'new' => true],
        );

        self::assertSame([
            'org'      => 1,
            'projects' => [1, 2, 3],
            'meta'     => ['a' => 1, 'tags' => ['x', 'y'], 'b' => 2],
            'empty'    => [5],
            'scalar'   => ['now' => 'array'],
            'new'      => true,
        ], $merged);
    }

    public function testAcceptingEventKeepsTheFirstRefusal(): void
    {
        $event = new InvitationAcceptingEvent($this->snapshot(), new DateTimeImmutable());
        self::assertFalse($event->isRefused());
        self::assertNull($event->getRefusal());

        $event->refuse(new InvitationRefusal('first'));
        $event->refuse(new InvitationRefusal('second'));
        self::assertTrue($event->isRefused());
        self::assertSame('first', $event->getRefusal()?->key);

        $neutral = new InvitationAcceptingEvent($this->snapshot(), new DateTimeImmutable());
        $neutral->refuse();
        self::assertTrue($neutral->isRefused());
        self::assertNull($neutral->getRefusal());
    }

    public function testRefusalTranslatesInTheHostDomain(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())->method('trans')->with('seat.none', ['%n%' => 0], 'host', 'es')->willReturn('Sin plazas');

        self::assertSame('Sin plazas', (new InvitationRefusal('seat.none', ['%n%' => 0], 'host'))->trans($translator, 'es'));

        $exception = InvitationRefusedException::because(new InvitationRefusal('seat.none'));
        self::assertSame('seat.none', $exception->reason?->key);
        self::assertSame('Invitation refused by the host.', $exception->getMessage());
        self::assertNull((new InvitationRefusedException('plain'))->reason);
    }

    public function testFailedAcceptanceHasNoUser(): void
    {
        $failed = InvitationAcceptance::failed(InvitationAcceptance::NOT_ACCEPTED);
        self::assertFalse($failed->isAccepted());
        self::assertNull($failed->invitation);
        self::assertNull($failed->refusal);
    }

    private function snapshot(): InvitationSnapshot
    {
        $now = new DateTimeImmutable();

        return new InvitationSnapshot('pid', 'default', 'a@example.com', [], [], null, null, null, 7, $now, $now, 1, $now, null, null, null, null, InvitationStatus::Pending);
    }
}
