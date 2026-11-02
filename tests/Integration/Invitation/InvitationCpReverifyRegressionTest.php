<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Invitation;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use InvalidArgumentException;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Invitation\InvitationManager;
use Nowo\AuthKitBundle\Invitation\InvitationOutcome;
use Nowo\AuthKitBundle\Invitation\InvitationRequest;
use Nowo\AuthKitBundle\Invitation\InvitationTokens;
use Nowo\AuthKitBundle\Invitation\Security\InvitationActionAuthorizerInterface;
use Nowo\AuthKitBundle\Repository\InvitationRepository;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\User\UserInterface;

use function get_debug_type;

/**
 * SEC-004 Appendix CP re-verification (1.33 on 9bd97cf) PoCs turned into regression tests: N1 the shown-once link
 * follows the returned-link rule, N2 expired invitations do not count toward TOO_MANY_OPEN, N3 boolean / float merge
 * key values are refused and a merge keeps the stored key value.
 */
final class InvitationCpReverifyRegressionTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return InvitationsHostApiShowLinkTestKernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $cache = self::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();
        $em   = $this->em();
        $tool = new SchemaTool($em);
        $meta = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($meta);
        $tool->createSchema($meta);
        $em->persist((new KernelUser())->setEmail('super@example.com')->setPassword('x')->setRoles(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']));
        $em->persist((new KernelUser())->setEmail('member@example.com')->setPassword('x')->setRoles(['ROLE_USER']));
        $em->flush();
        $em->clear();
        $this->hooks()->authorize = null;
        $this->hooks()->asked     = [];
        $this->mailer()->fail     = false;
    }

    /** CP N1 (PoC testDefaultNoAuthorizerRolelessAndShowLinkPath): no shown-once link for a null actor or without return_link. */
    public function testShownOnceLinkNeedsAnActorAndTheReturnLinkAuthorizer(): void
    {
        $this->manager()->invite($this->user('super@example.com'), 'default', new InvitationRequest('v@example.com', payload: ['organization' => 2]));
        $this->mailer()->fail = true;

        $resend = $this->manager()->resend(null, $this->invitation('v@example.com'));
        self::assertSame(InvitationOutcome::NOT_DELIVERED, $resend->status);
        self::assertNull($resend->url);
        $merge = $this->manager()->invite(null, 'default', new InvitationRequest('v@example.com', payload: ['organization' => 2, 'org_role' => 'owner']));
        self::assertSame(InvitationOutcome::NOT_DELIVERED, $merge->status);
        self::assertNull($merge->url);

        // A signed-in actor still gets the shown-once link, unless the host authorizer refuses return_link.
        $this->mailer()->fail = false;
        $this->manager()->invite($this->user('super@example.com'), 'default', new InvitationRequest('w@example.com', payload: ['organization' => 3]));
        $this->mailer()->fail     = true;
        $member                   = $this->user('member@example.com');
        $this->hooks()->authorize = static fn (?UserInterface $actor, string $action): bool => $action !== InvitationActionAuthorizerInterface::RETURN_LINK;
        $denied                   = $this->manager()->resend($member, $this->invitation('w@example.com'));
        self::assertSame(InvitationOutcome::NOT_DELIVERED, $denied->status);
        self::assertNull($denied->url);
        $this->hooks()->authorize = null;
        $shown                    = $this->manager()->resend($member, $this->invitation('w@example.com'));
        self::assertSame(InvitationOutcome::LINK, $shown->status);
        self::assertNotNull($shown->url);
    }

    /** CP N2 (PoC testTooManyOpenDos): 501 expired open invitations do not block a new invitation of the e-mail. */
    public function testExpiredInvitationsDoNotCountTowardTooManyOpen(): void
    {
        $em     = $this->em();
        $tokens = new InvitationTokens();
        $then   = new DateTimeImmutable('-60 days');
        for ($i = 0; $i <= InvitationRepository::MERGE_SCAN_LIMIT; ++$i) {
            $stale = (new Invitation('default', 'victim@example.com', 'mallory@example.com', 1, $then))->setPayload(['organization' => 'junk' . $i]);
            $stale->issue($tokens->generate()[1], 1, $then);
            $em->persist($stale);
        }
        $em->flush();
        $em->clear();

        $legit = $this->manager()->invite($this->user('super@example.com'), 'default', new InvitationRequest('victim@example.com', payload: ['organization' => 'legit']));
        self::assertSame(InvitationOutcome::SENT, $legit->status);
        // An expired invitation of the same key is still found (resent, not forked).
        $again = $this->manager()->invite($this->user('super@example.com'), 'default', new InvitationRequest('victim@example.com', payload: ['organization' => 'junk500']));
        self::assertSame(InvitationOutcome::SENT, $again->status);
        self::assertSame('junk500', $again->invitation?->payload['organization'] ?? null);
        self::assertSame(InvitationRepository::MERGE_SCAN_LIMIT + 2, (int) $this->em()->createQuery('SELECT COUNT(i) FROM ' . Invitation::class . " i WHERE i.email = 'victim@example.com'")->getSingleScalarResult());
    }

    /** CP N3 (PoC testMergeBoolCollision): true / false / 1.0 refused; a merge keeps the stored key value. */
    public function testBooleanAndFloatMergeValuesAreRefusedAndTheStoredValueKept(): void
    {
        $super = $this->user('super@example.com');
        $one   = $this->manager()->invite($super, 'default', new InvitationRequest('c@example.com', payload: ['organization' => 1, 'org_role' => 'member']));
        foreach ([true, false, 1.0, 0.0] as $value) {
            try {
                $this->manager()->invite($super, 'default', new InvitationRequest('c@example.com', payload: ['organization' => $value, 'org_role' => 'owner']));
                self::fail('A ' . get_debug_type($value) . ' merge key value must be refused.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('must be a string, an integer or null', $exception->getMessage());
            }
        }
        $merged = $this->manager()->invite($super, 'default', new InvitationRequest('c@example.com', payload: ['organization' => '1', 'org_role' => 'admin']));
        self::assertSame($one->invitation?->publicId, $merged->invitation?->publicId);
        self::assertSame(['organization' => 1, 'org_role' => 'admin'], $this->invitation('c@example.com')->getPayload());
    }

    private function manager(): InvitationManager
    {
        $manager = self::getContainer()->get(InvitationManager::class);
        self::assertInstanceOf(InvitationManager::class, $manager);

        return $manager;
    }

    private function mailer(): CapturingInvitationMailer
    {
        $mailer = self::getContainer()->get(CapturingInvitationMailer::class);
        self::assertInstanceOf(CapturingInvitationMailer::class, $mailer);

        return $mailer;
    }

    private function hooks(): HostHookListener
    {
        $hooks = self::getContainer()->get(HostHookListener::class);
        self::assertInstanceOf(HostHookListener::class, $hooks);

        return $hooks;
    }

    private function invitation(string $email): Invitation
    {
        $this->em()->clear();
        $invitation = $this->em()->getRepository(Invitation::class)->findOneBy(['email' => $email], ['id' => 'DESC']);
        self::assertInstanceOf(Invitation::class, $invitation);

        return $invitation;
    }

    private function user(string $email): KernelUser
    {
        $user = $this->em()->getRepository(KernelUser::class)->find($email);
        self::assertInstanceOf(KernelUser::class, $user);

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
