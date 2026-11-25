<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Webauthn;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Entity\WebauthnCredential;
use Nowo\AuthKitBundle\Mfa\MfaOwnerResolver;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Support\Webauthn\SoftwareAuthenticator;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnCredentialRemovedEvent;
use Nowo\AuthKitBundle\Webauthn\PersonalData\WebauthnPersonalDataEraser;
use Nowo\AuthKitBundle\Webauthn\PersonalData\WebauthnPersonalDataExportProvider;
use Nowo\AuthKitBundle\Webauthn\WebauthnStore;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_keys;

/**
 * GDPR hooks of the passkeys (2.4.0): the UserKit export section lists what is stored without key material, the
 * eraser deletes every passkey of the user (also under the identifier captured when the erasure started) and nobody
 * else's, and both tolerate a missing table.
 */
final class WebauthnPersonalDataKernelTest extends WebauthnKernelTestCase
{
    #[Test]
    public function thePasskeysAreExportedAndErased(): void
    {
        foreach (['member@example.com' => 'member-password-123', 'admin@example.com' => 'admin-password-123'] as $email => $password) {
            $this->client->getCookieJar()->clear();
            $this->signedIn($email, $password);
            $this->registerPasskey(new SoftwareAuthenticator(), 'Key of ' . $email);
        }
        [$eraser, $export] = $this->hooks();
        self::assertSame(WebauthnPersonalDataEraser::KEY, $eraser->getKey());
        self::assertSame(WebauthnPersonalDataExportProvider::SECTION, $export->getSectionKey());
        self::assertTrue($eraser->supports($this->user('member@example.com')));
        self::assertFalse($eraser->supports(new stdClass()));
        self::assertTrue($export->supports($this->user('member@example.com')));
        self::assertFalse($export->supports(new stdClass()));
        self::assertNull($export->export(new stdClass()));
        self::assertNull($export->export($this->user('support@example.com')));

        $data = $export->export($this->user('member@example.com'));
        self::assertIsArray($data);
        self::assertCount(1, $data);
        self::assertSame(['profile', 'label', 'created_at', 'last_used_at', 'transports', 'synced', 'aaguid'], array_keys($data[0]));
        self::assertSame('Key of member@example.com', $data[0]['label']);
        self::assertSame(['hybrid', 'internal'], $data[0]['transports']);

        $eraser->erase(new ErasureRequest($this->user('member@example.com')));
        $eraser->erase(new ErasureRequest(new stdClass()));
        self::assertNull($export->export($this->user('member@example.com')));
        self::assertCount(1, $this->em()->getRepository(WebauthnCredential::class)->findAll(), 'The other account keeps its passkey.');
        $removed = $this->eventsOf(WebauthnCredentialRemovedEvent::class);
        self::assertCount(1, $removed);
        self::assertSame(WebauthnCredentialRemovedEvent::REASON_ERASURE, $removed[0]->reason);
    }

    #[Test]
    public function aPasskeyKeyedByTheOriginalIdentifierIsErased(): void
    {
        $this->signedIn('member@example.com', 'member-password-123');
        $this->registerPasskey(new SoftwareAuthenticator());
        [$eraser] = $this->hooks();
        // A host eraser already anonymised the e-mail, which is the id of this user class.
        $anonymised = (new KernelUser())->setEmail('erased-1@example.invalid')->setPassword('x');
        $eraser->erase(new ErasureRequest($anonymised, null, [], 'member@example.com'));
        self::assertSame([], $this->em()->getRepository(WebauthnCredential::class)->findAll());
    }

    #[Test]
    public function aMissingTableIsNothingToEraseOrExport(): void
    {
        $this->em()->getConnection()->executeStatement('DROP TABLE ' . WebauthnCredential::TABLE);
        [$eraser, $export] = $this->hooks();
        $eraser->erase(new ErasureRequest($this->user('member@example.com')));
        self::assertNull($export->export($this->user('member@example.com')));
    }

    /**
     * @return array{0: WebauthnPersonalDataEraser, 1: WebauthnPersonalDataExportProvider}
     */
    private function hooks(): array
    {
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $store  = new WebauthnStore($doctrine);
        $owners = new MfaOwnerResolver($doctrine);

        return [new WebauthnPersonalDataEraser($store, $owners, $dispatcher), new WebauthnPersonalDataExportProvider($store, $owners)];
    }
}
