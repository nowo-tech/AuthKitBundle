<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Database\Support;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Invitation\InvitationTokens;
use Nowo\AuthKitBundle\Repository\InvitationRepository;
use Nowo\AuthKitBundle\Transaction\AuthKitTransactionRunner;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 1.53.0 (REQ-RUNTIME-003 rule 6): a request served by an AuthKit-namespaced controller that, with `?commit=1`, first
 * commits one AuthKit unit (an invitation), then runs a second unit that locks the invitation of locked@example.com,
 * which a peer session holds: the lock wait fails the second unit after the first one committed.
 */
final readonly class CommitThenConflictController
{
    public const PATH = '/load/commit-then-conflict';

    public function __construct(private AuthKitTransactionRunner $runner, private InvitationRepository $invitations)
    {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->query->getBoolean('commit')) {
            $now        = new DateTimeImmutable();
            $invitation = new Invitation('default', 'first@example.com', null, 7, $now);
            $invitation->issue((new InvitationTokens())->generate()[1], 7, $now);
            $this->invitations->save($invitation);
        }
        $connection = $this->runner->connection();
        $this->runner->run(static fn (): mixed => $connection->fetchOne('SELECT id FROM auth_kit_invitation WHERE email = ? FOR UPDATE', ['locked@example.com']));

        return new Response('written');
    }
}
