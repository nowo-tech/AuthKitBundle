<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Database;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Invitation\InvitationTokens;
use Nowo\AuthKitBundle\Repository\InvitationRepository;
use Nowo\AuthKitBundle\Tests\Database\Support\CommitThenConflictController;
use Nowo\AuthKitBundle\Tests\Database\Support\LoadDatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * 1.53.0 (REQ-RUNTIME-003 rule 6), two sessions on MySQL 8.4 / MariaDB 11.4 / PostgreSQL 16, through the HTTP kernel:
 * a peer session holds the row an AuthKit page writes, the page's lock wait times out, and the answer is 409 +
 * Retry-After + CONFLICT_001 (nothing committed: the retry then succeeds once), or 409 + CONFLICT_002 without
 * Retry-After when the same request had already committed a unit (counted by the commit tracking middleware). The
 * stored data is all or nothing and the worker's connection stays usable.
 */
final class ConflictAnswerTest extends LoadDatabaseTestCase
{
    public function testALockWaitOnTheInvitationAcceptanceAnswers409WithConflict001AndTheRetryAcceptsOnce(): void
    {
        [$token, $hash] = (new InvitationTokens())->generate();
        $now            = new DateTimeImmutable();
        $invitation     = new Invitation('default', 'ada@example.com', null, 7, $now);
        $invitation->issue($hash, 7, $now);
        $this->get('load.invitations', InvitationRepository::class)->save($invitation);
        $browser = $this->browser();
        $browser->request('GET', '/invitation/' . $token);
        self::assertSame(Response::HTTP_SEE_OTHER, $browser->getResponse()->getStatusCode());
        $crawler = $browser->request('GET', '/invitation');
        self::assertSame(Response::HTTP_OK, $browser->getResponse()->getStatusCode());
        $form = ['registration_form' => [
            'password' => ['first' => 'correct-horse-battery', 'second' => 'correct-horse-battery'],
            '_token'   => (string) $crawler->filter('input[name="registration_form[_token]"]')->attr('value'),
        ]];

        $peer = $this->otherSession();
        self::assertSame('OK', $peer->execute('BEGIN'));
        self::assertSame('OK', $peer->execute('SELECT id FROM auth_kit_invitation WHERE id = ' . $this->invitationId('ada@example.com') . ' FOR UPDATE'));
        $this->shortLockWait();

        // A page: the application's error page with the status and Retry-After.
        $browser->request('POST', '/invitation', $form);
        $page = $browser->getResponse();
        self::assertSame(Response::HTTP_CONFLICT, $page->getStatusCode());
        self::assertSame('1', $page->headers->get('Retry-After'));
        self::assertSame('CONFLICT_001', $page->headers->get('X-Conflict-Code'));
        self::assertStringNotContainsStringIgnoringCase('FOR UPDATE', (string) $page->getContent(), 'no SQL');

        // A JSON client: the 1.50 keys and the code.
        $browser->request('POST', '/invitation', $form, [], ['HTTP_ACCEPT' => 'application/json']);
        $json = $browser->getResponse();
        self::assertSame(Response::HTTP_CONFLICT, $json->getStatusCode());
        self::assertSame('1', $json->headers->get('Retry-After'));
        self::assertSame('CONFLICT_001', $json->headers->get('X-Conflict-Code'));
        self::assertSame(['ok' => false, 'error' => 'unavailable', 'code' => 'CONFLICT_001'], json_decode((string) $json->getContent(), true, 512, JSON_THROW_ON_ERROR));

        $this->assertHealthy();
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM kernel_users'), 'nothing committed');
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM auth_kit_invitation WHERE accepted_at IS NOT NULL'));
        self::assertSame('OK', $peer->execute('ROLLBACK'));

        // Retry-safe: the same form, sent again, accepts the invitation once.
        $browser->request('POST', '/invitation', $form);
        self::assertStringEndsWith('/home', (string) $browser->getResponse()->headers->get('Location'), 'accepted and signed in');
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM kernel_users WHERE email = 'ada@example.com'"));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM auth_kit_invitation WHERE accepted_at IS NOT NULL'));
        $this->assertHealthy();
    }

    public function testAConflictAfterTheRequestCommittedAUnitAnswersConflict002WithoutRetryAfter(): void
    {
        $this->invitation('locked@example.com');
        $peer = $this->otherSession();
        self::assertSame('OK', $peer->execute('BEGIN'));
        // By primary key: a record lock only (no gap lock that would block the first unit's insert on MySQL / MariaDB).
        self::assertSame('OK', $peer->execute('SELECT id FROM auth_kit_invitation WHERE id = ' . $this->invitationId('locked@example.com') . ' FOR UPDATE'));
        $this->shortLockWait();
        $browser = $this->browser();

        // Nothing committed before the lock wait: retry-safe.
        $browser->request('POST', CommitThenConflictController::PATH, [], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(Response::HTTP_CONFLICT, $browser->getResponse()->getStatusCode());
        self::assertSame(['ok' => false, 'error' => 'unavailable', 'code' => 'CONFLICT_001'], json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('1', $browser->getResponse()->headers->get('Retry-After'));

        // The first unit committed, then the second met the lock: read again, do not repeat.
        $browser->request('POST', CommitThenConflictController::PATH . '?commit=1', [], [], ['HTTP_ACCEPT' => 'application/json']);
        $json = $browser->getResponse();
        self::assertSame(Response::HTTP_CONFLICT, $json->getStatusCode());
        self::assertSame(['ok' => false, 'error' => 'unavailable', 'code' => 'CONFLICT_002'], json_decode((string) $json->getContent(), true, 512, JSON_THROW_ON_ERROR));
        self::assertFalse($json->headers->has('Retry-After'));
        self::assertSame('CONFLICT_002', $json->headers->get('X-Conflict-Code'));

        // A page gets the same status, without Retry-After.
        $browser->request('POST', CommitThenConflictController::PATH . '?commit=1');
        self::assertSame(Response::HTTP_CONFLICT, $browser->getResponse()->getStatusCode());
        self::assertFalse($browser->getResponse()->headers->has('Retry-After'));
        self::assertSame('CONFLICT_002', $browser->getResponse()->headers->get('X-Conflict-Code'));

        $this->assertHealthy();
        self::assertSame(2, $this->rows("SELECT COUNT(*) FROM auth_kit_invitation WHERE email = 'first@example.com'"), 'the committed unit of each request stays, once');
        self::assertSame('OK', $peer->execute('ROLLBACK'));
        $browser->request('POST', CommitThenConflictController::PATH);
        self::assertSame('written', $browser->getResponse()->getContent());
    }

    private function invitationId(string $email): int
    {
        return (int) $this->connection()->fetchOne('SELECT id FROM auth_kit_invitation WHERE email = ?', [$email]);
    }

    private function browser(): KernelBrowser
    {
        $browser = new KernelBrowser($this->kernel);
        $browser->disableReboot();

        return $browser;
    }
}
