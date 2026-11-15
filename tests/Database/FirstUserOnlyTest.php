<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Database;

use Closure;
use Nowo\AuthKitBundle\Tests\Database\Support\DatabaseTestKernel;
use Nowo\AuthKitBundle\Tests\Database\Support\LoadDatabaseTestCase;

use function fclose;
use function getenv;
use function json_decode;
use function json_encode;
use function microtime;
use function proc_close;
use function proc_open;
use function sprintf;
use function str_starts_with;
use function stream_get_contents;
use function trim;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * Audit QG-M1 (1.50.0), two real PHP processes (two workers, kernel reset off) on MySQL 8.4 / PostgreSQL 16, with
 * `registration_mode: first_user_only` (the default) and social sign-up (`create_user_if_missing`): while the user
 * table is empty, the first account is created once, whichever way the two requests come (registration page or social
 * callback). The first writer holds its unit open after its "no account yet" check; the second one starts meanwhile and
 * must be refused once the first one committed. Before the fix a social sign-up never took the user class lock, and on
 * MySQL the registration page's second count read its old REPEATABLE READ snapshot: two "first" accounts.
 */
final class FirstUserOnlyTest extends LoadDatabaseTestCase
{
    public function testASocialSignUpAndARegistrationCreateOneFirstAccount(): void
    {
        $this->assertOneFirstAccount('social', 'form');
    }

    public function testTwoSocialSignUpsCreateOneFirstAccount(): void
    {
        $this->assertOneFirstAccount('social', 'social');
    }

    public function testARegistrationAndASocialSignUpCreateOneFirstAccount(): void
    {
        $this->assertOneFirstAccount('form', 'social');
    }

    private function assertOneFirstAccount(string $first, string $second): void
    {
        // The first writer's unit stays open 1.5 s before its insert; the second one starts 0.5 s after it.
        $results = $this->run2(static fn (float $start): array => [
            [$first, 'first@example.com', $start, '1.5'],
            [$second, 'second@example.com', $start + 0.5, null],
        ]);

        self::assertSame('created', $results[0], 'the first writer: ' . json_encode($results));
        self::assertTrue(str_starts_with($results[1], 'refused'), 'the second writer is refused: ' . json_encode($results));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM kernel_users'), 'one first account');
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM kernel_users WHERE email = 'first@example.com'"));
    }

    /**
     * @param Closure(float): list<array{string, string, float, ?string}> $writers mode, e-mail, start, AUTH_KIT_LOAD_SLOW_PERSIST per writer, from the common start
     *
     * @return list<string> the outcome of each writer
     */
    private function run2(Closure $writers): array
    {
        // The writers' kernel is compiled once here, not by both processes at the same time.
        $kernel = new DatabaseTestKernel('test_first', true);
        $kernel->boot();
        $kernel->shutdown();
        $processes = [];
        foreach ($writers(microtime(true) + 3.0) as $index => [$mode, $email, $start, $slow]) {
            $env = getenv();
            if ($slow !== null) {
                $env['AUTH_KIT_LOAD_SLOW_PERSIST'] = $slow;
            }
            // Test-only helper processes; their output is read to the end below.
            // @phpstan-ignore frankenphp.classic.noUnlimitedIoTimeout
            $process = proc_open([PHP_BINARY, __DIR__ . '/Support/first-user-writer.php', $mode, $email, sprintf('%.6F', $start)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
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
            /** @var array{outcome: string} $decoded */
            $decoded   = json_decode(trim($out), true, 512, JSON_THROW_ON_ERROR);
            $results[] = $decoded['outcome'];
        }

        return $results;
    }
}
