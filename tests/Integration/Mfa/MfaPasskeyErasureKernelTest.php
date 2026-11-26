<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Mfa\MfaOwner;
use Nowo\AuthKitBundle\Mfa\MfaOwnerResolver;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnCredentialRemovedEvent;
use Nowo\AuthKitBundle\Webauthn\WebauthnErasure;
use Nowo\AuthKitBundle\Webauthn\WebauthnManager;
use Nowo\AuthKitBundle\Webauthn\WebauthnStore;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function bin2hex;
use function random_bytes;

/**
 * Since 2.4.1 (audit ZBX-v-I2): with passkeys switched off in every profile (no WebauthnManager), forgetting a user
 * through MfaManager still deletes the passkeys stored while they were on; another user's stay.
 */
final class MfaPasskeyErasureKernelTest extends MfaKernelTestCase
{
    #[Test]
    public function forgettingAUserDeletesItsStoredPasskeysWhilePasskeysAreOff(): void
    {
        self::assertFalse(self::getContainer()->has(WebauthnManager::class), 'passkeys are off in this kernel');
        $removed    = [];
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(WebauthnCredentialRemovedEvent::class, static function (WebauthnCredentialRemovedEvent $event) use (&$removed): void {
            $removed[] = $event->reason;
        });
        $admin  = $this->ownerOf('admin@example.com');
        $member = $this->ownerOf('member@example.com');
        $this->storePasskey($admin, 'default');
        $this->storePasskey($admin, 'other');
        $this->storePasskey($member, 'default');

        $this->manager()->forgetUser($this->user('admin@example.com'));

        self::assertSame([], $this->store()->allOf($admin->type, $admin->id));
        self::assertCount(1, $this->store()->allOf($member->type, $member->id), 'another user keeps its passkey');
        self::assertSame([WebauthnCredentialRemovedEvent::REASON_ERASURE, WebauthnCredentialRemovedEvent::REASON_ERASURE], $removed);

        $erasure = self::getContainer()->get(WebauthnErasure::class);
        self::assertInstanceOf(WebauthnErasure::class, $erasure);
        self::assertSame(0, $erasure->forgetUser($this->user('admin@example.com')), 'idempotent');
        self::assertSame(1, $erasure->forgetUser($this->user('member@example.com')), 'the public service, for hosts without MFA');
    }

    private function ownerOf(string $email): MfaOwner
    {
        $owner = new MfaOwnerResolver($this->doctrine())->resolve($this->user($email));
        self::assertInstanceOf(MfaOwner::class, $owner);

        return $owner;
    }

    private function storePasskey(MfaOwner $owner, string $profile): void
    {
        $raw = random_bytes(16);
        self::assertTrue($this->store()->insert(bin2hex(random_bytes(8)), $profile, $owner, $raw, 'handle-' . bin2hex($raw), 'public-key', 0, [], null, null, null, 'Laptop', new DateTimeImmutable()));
    }

    private function store(): WebauthnStore
    {
        return new WebauthnStore($this->doctrine());
    }

    private function doctrine(): ManagerRegistry
    {
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);

        return $doctrine;
    }
}
