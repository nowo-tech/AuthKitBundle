<?php

declare(strict_types=1);

/*
 * One of two concurrent writers of tests/Database/ConcurrentAccountsTest (1.50.0, Beacon spec 239): each call is one
 * request of a FrankenPHP worker with kernel reset off; after it, what the next request would find is recorded (a
 * transaction left open, a closed manager), then the unit-of-work boundary runs.
 *
 *   concurrent-writer.php <register|accept|mixed> <identifiers> <start timestamp>
 *
 * Every writer creates the same identifiers (`load<i>@example.com`), registering them or accepting the invitation of
 * the same address. Prints one JSON line: {created, accepted, refused, failures, poisoned, closed}.
 */

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Doctrine\PrivateEntityManagerSubscriber;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptance;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptor;
use Nowo\AuthKitBundle\Invitation\InvitationTokens;
use Nowo\AuthKitBundle\Security\UserRegistrar;
use Nowo\AuthKitBundle\Tests\Database\Support\DatabaseTestKernel;
use Nowo\TransactionContract\TransientStorageFailure;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $mode, $count, $start] = $argv + [null, 'register', '20', '0'];
$kernel                   = new DatabaseTestKernel('test', true);
$kernel->boot();
$container = $kernel->getContainer();
$registry  = $container->get('doctrine');
assert($registry instanceof ManagerRegistry);
$registrar = $container->get('load.registrar');
assert($registrar instanceof UserRegistrar);
$acceptor = $container->get('load.acceptor');
assert($acceptor instanceof InvitationAcceptor);
$boundary = $container->get('load.boundary');
assert($boundary instanceof PrivateEntityManagerSubscriber);
$em = static function () use ($registry): EntityManagerInterface {
    $manager = $registry->getManager();
    assert($manager instanceof EntityManagerInterface);

    return $manager;
};
$tokens = new InvitationTokens();
while (microtime(true) < (float) $start) {
    usleep(1000);
}

$result = ['created' => 0, 'accepted' => 0, 'refused' => 0, 'failures' => [], 'poisoned' => 0, 'closed' => 0];
for ($i = 0; $i < (int) $count; ++$i) {
    $email = 'load' . $i . '@example.com';
    $op    = $mode === 'mixed' ? ($i % 2 === 0 ? 'register' : 'accept') : $mode;
    try {
        if ($op === 'register') {
            $registrar->register(['email' => $email, 'password' => 'secret-123'], 'default');
            ++$result['created'];
        } else {
            $hash       = $tokens->hash('token-' . $email);
            $invitation = $em()->getRepository(Invitation::class)->findOneBy(['tokenHash' => $hash]) ?? throw new RuntimeException('invitation ' . $email);
            $outcome    = $acceptor->attempt($invitation, $hash, ['email' => $email, 'password' => 'secret-123']);
            $outcome->status === InvitationAcceptance::ACCEPTED ? ++$result['accepted'] : ++$result['refused'];
        }
    } catch (TransientStorageFailure $failure) {
        $result['failures'][$op . ': transient'] = ($result['failures'][$op . ': transient'] ?? 0) + 1;
    } catch (RuntimeException $refusal) {
        // The identifier was taken by the other writer: refused before any insert.
        ++$result['refused'];
    } catch (Throwable $failure) {
        $result['failures'][$op . ': ' . $failure::class] = ($result['failures'][$op . ': ' . $failure::class] ?? 0) + 1;
    }
    // What the next request of this worker finds.
    if ($em()->getConnection()->getTransactionNestingLevel() > 0) {
        ++$result['poisoned'];
    }
    if (!$em()->isOpen()) {
        ++$result['closed'];
    }
    $boundary->clearState();
    $em()->clear();
}

echo json_encode($result, \JSON_THROW_ON_ERROR), "\n";
