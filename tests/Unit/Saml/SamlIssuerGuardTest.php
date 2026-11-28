<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Saml;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use LogicException;
use Nowo\AuthKitBundle\Entity\SamlProvider;
use Nowo\AuthKitBundle\Repository\SocialLoginAccountRepository;
use Nowo\AuthKitBundle\Saml\SamlIssuerGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(SamlIssuerGuard::class)]
final class SamlIssuerGuardTest extends TestCase
{
    public function testTheEntityIdIsLockedWhileLinksExist(): void
    {
        $row = (new SamlProvider())->setProvider('acme')->setIdpEntityId('https://idp.example.com');

        self::assertNull($this->guard(0)->entityIdChangeError($row, 'https://other.example.com'));
        self::assertNull($this->guard(3)->entityIdChangeError($row, 'https://idp.example.com'));
        self::assertNull($this->guard(3)->entityIdChangeError(new SamlProvider(), 'https://idp.example.com'), 'A row never set up.');
        self::assertStringContainsString('"acme"', (string) $this->guard(3)->entityIdChangeError($row, 'https://other.example.com'));
        $this->guard(0)->assertEntityIdChangeAllowed($row, 'https://other.example.com');

        $this->expectException(LogicException::class);
        $this->guard(3)->assertEntityIdChangeAllowed($row, 'https://other.example.com');
    }

    public function testTheFlushIsRefusedBeforeAnythingIsWritten(): void
    {
        $row        = (new SamlProvider())->setProvider('acme')->setIdpEntityId('https://idp.example.com');
        $same       = (new SamlProvider())->setProvider('other');
        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('getScheduledEntityUpdates')->willReturn([new stdClass(), $same, $row]);
        $unitOfWork->method('getEntityChangeSet')->willReturnCallback(static fn (object $entity): array => $entity === $row ? ['idpEntityId' => ['https://idp.example.com', 'https://other.example.com']] : ['label' => ['a', 'b']]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($unitOfWork);

        $this->guard(0)->onFlush(new OnFlushEventArgs($em));
        $this->expectException(LogicException::class);
        $this->guard(1)->onFlush(new OnFlushEventArgs($em));
    }

    private function guard(int $links): SamlIssuerGuard
    {
        $accounts = $this->createStub(SocialLoginAccountRepository::class);
        $accounts->method('count')->willReturn($links);

        return new SamlIssuerGuard($accounts);
    }
}
