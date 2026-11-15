<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Database;

use Nowo\AuthKitBundle\Tests\Database\Support\LoadDatabaseTestCase;

use function fclose;
use function json_decode;
use function json_encode;
use function microtime;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function trim;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * 1.50.0 (Beacon spec 239): two real PHP processes (two workers, kernel reset off) create the same accounts at the same
 * time, by registration and by accepting the invitation of the same address, on MySQL 8.4 / PostgreSQL 16. Every
 * identifier gets exactly one account, every invitation is accepted at most once, nothing answers a 500 (only a
 * refusal or a transient 503), and no worker keeps an open transaction or a closed manager into its next request.
 */
final class ConcurrentAccountsTest extends LoadDatabaseTestCase
{
    private const IDENTIFIERS = 30;

    public function testTwoWorkersNeverCreateTwoAccountsForOneIdentifier(): void
    {
        for ($i = 0; $i < self::IDENTIFIERS; ++$i) {
            $this->invitation('load' . $i . '@example.com');
        }

        $results = $this->run2(['register', 'mixed']);

        $created  = 0;
        $accepted = 0;
        foreach ($results as $result) {
            self::assertSame([], $result['failures'], 'no other failure than a transient one: ' . json_encode($results));
            self::assertSame(0, $result['poisoned'], 'no transaction left open for the next request');
            self::assertSame(0, $result['closed'], 'no manager closed by a unique key');
            $created += $result['created'];
            $accepted += $result['accepted'];
        }
        self::assertSame(self::IDENTIFIERS, $this->rows('SELECT COUNT(*) FROM kernel_users'), 'one account per identifier');
        self::assertSame(self::IDENTIFIERS, $created + $accepted);
        self::assertSame($accepted, $this->rows('SELECT COUNT(*) FROM auth_kit_invitation WHERE accepted_at IS NOT NULL'), 'an invitation is accepted only with its account');
    }

    public function testTwoWorkersAcceptingTheSameInvitationsAcceptEachOnce(): void
    {
        for ($i = 0; $i < self::IDENTIFIERS; ++$i) {
            $this->invitation('load' . $i . '@example.com');
        }

        $results = $this->run2(['accept', 'accept']);

        foreach ($results as $result) {
            self::assertSame([], $result['failures'], 'no other failure than a transient one: ' . json_encode($results));
            self::assertSame(0, $result['poisoned']);
            self::assertSame(0, $result['closed']);
        }
        self::assertSame(self::IDENTIFIERS, $results[0]['accepted'] + $results[1]['accepted'], 'every invitation accepted exactly once');
        self::assertSame(self::IDENTIFIERS, $this->rows('SELECT COUNT(*) FROM kernel_users'));
        self::assertSame(self::IDENTIFIERS, $this->rows('SELECT COUNT(*) FROM auth_kit_invitation WHERE accepted_at IS NOT NULL'));
    }

    /**
     * @param array{string, string} $modes
     *
     * @return list<array{created: int, accepted: int, refused: int, failures: array<string, int>, poisoned: int, closed: int}>
     */
    private function run2(array $modes): array
    {
        $start     = microtime(true) + 3.0;
        $processes = [];
        foreach ($modes as $index => $mode) {
            // Test-only helper processes; their output is read to the end below.
            // @phpstan-ignore frankenphp.classic.noUnlimitedIoTimeout
            $process = proc_open([PHP_BINARY, __DIR__ . '/Support/concurrent-writer.php', $mode, (string) self::IDENTIFIERS, (string) $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $processes[$index] = [$process, $pipes];
        }
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $err . $out);
            /** @var array{created: int, accepted: int, refused: int, failures: array<string, int>, poisoned: int, closed: int} $decoded */
            $decoded   = json_decode(trim($out), true, 512, JSON_THROW_ON_ERROR);
            $results[] = $decoded;
        }

        return $results;
    }
}
