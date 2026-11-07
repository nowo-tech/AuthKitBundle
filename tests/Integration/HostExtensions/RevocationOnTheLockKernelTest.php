<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\HostExtensions;

use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Tests\Integration\MultiAccount\MultiAccountKernelTestCase;
use RuntimeException;

use function bin2hex;
use function file_exists;
use function file_get_contents;
use function getenv;
use function is_string;
use function microtime;
use function parse_str;
use function parse_url;
use function proc_close;
use function proc_open;
use function random_bytes;
use function sys_get_temp_dir;
use function touch;
use function usleep;

use const PHP_BINARY;
use const PHP_URL_QUERY;

/**
 * GY-L7, C1 of Appendix HU: a sign-in fails while it holds the earlier session's row, and another process signing out
 * everywhere waits on that row. The revocation lands and the session ends. Needs a server with row locks: the CI job
 * `real-databases` runs it on PostgreSQL and MySQL.
 */
final class RevocationOnTheLockKernelTest extends MultiAccountKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return MultiAccountHandlerTestKernel::class;
    }

    protected function setUp(): void
    {
        $url = getenv('AUTH_KIT_TEST_DB_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Needs AUTH_KIT_TEST_DB_URL.');
        }
        parent::setUp();
        $this->em()->persist((new SocialLoginCredential())->setProvider('google')->setLabel('Google')->setEnabled(true)->setClientId('id-placeholder')->setClientSecret('secret-placeholder'));
        $this->em()->flush();
    }

    public function testTheRevocationWaitingOnTheRowEndsTheSession(): void
    {
        $this->signIn('admin@example.com', 'admin-password-123');
        $this->client->request('GET', '/mfa-home');

        $base    = sys_get_temp_dir() . '/auth-kit-lock-' . bin2hex(random_bytes(4));
        // @phpstan-ignore frankenphp.classic.noUnlimitedIoTimeout (test helper: waitFor() and the script have their own deadlines)
        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=-1', __DIR__ . '/revoke-everywhere-process.php', $base . '.flag', $base . '.started', 'admin@example.com', $base . '.result'], [], $pipes);
        self::assertIsResource($process);
        self::assertTrue($this->waitFor($base . '.result.ready'), 'The other process did not start.');

        $this->client->request('GET', '/login/social/google');
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $query);
        $state    = $query['state'] ?? null;
        $listener = self::getContainer()->get(ThrowingLoginListener::class);
        $provider = self::getContainer()->get(SocialProviderResponses::class);
        self::assertInstanceOf(ThrowingLoginListener::class, $listener);
        self::assertInstanceOf(SocialProviderResponses::class, $provider);
        $provider->email = 'member@example.com';
        $listener->late  = function () use ($base): never {
            touch($base . '.flag');
            $this->waitFor($base . '.started');
            usleep(800000);

            throw new RuntimeException('listener down');
        };

        $this->client->request('GET', '/login/social/google/check', ['code' => 'c', 'state' => $state]);

        self::assertResponseRedirects('/login');
        self::assertTrue($this->waitFor($base . '.result'), 'The other process did not finish.');
        proc_close($process);
        self::assertSame('1', file_get_contents($base . '.result'), 'It waited for the row and revoked it.');
        $this->client->request('GET', '/mfa-home');
        self::assertStringNotContainsString('admin@example.com|', (string) $this->client->getResponse()->getContent());
    }

    private function waitFor(string $file): bool
    {
        $deadline = microtime(true) + 60;
        while (!file_exists($file) && microtime(true) < $deadline) {
            usleep(20000);
        }

        return file_exists($file);
    }
}
