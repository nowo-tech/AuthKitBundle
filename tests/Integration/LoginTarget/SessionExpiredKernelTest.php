<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\LoginTarget;

use function json_decode;

/**
 * Standard Symfony host, real firewall (form_login, access_control): the expired-session 401 and the post-login guard.
 */
final class SessionExpiredKernelTest extends LoginTargetKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return LoginTargetTestKernel::class;
    }

    public function testBackgroundCallGetsTheProblem401AndSavesNoTarget(): void
    {
        $this->navigate('/secret');
        self::assertResponseRedirects('http://localhost/login');
        self::assertSame('http://localhost/secret', $this->savedTarget());

        $this->fetch('/api/poll');
        $response = $this->client->getResponse();
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame('session-expired', $response->headers->get('X-Auth-Kit'));
        self::assertSame('session_expired', json_decode((string) $response->getContent(), true)['code'] ?? null);
        self::assertSame('http://localhost/secret', $this->savedTarget(), 'The poll did not replace the page.');

        $this->fetch('/api/poll', 'POST');
        self::assertResponseStatusCodeSame(401);

        $this->signIn();
        self::assertResponseRedirects('http://localhost/secret');
    }

    public function testMachineTargetSavedByALegacyNavigationLandsOnTheFallback(): void
    {
        $this->legacyNavigate('/api/poll');
        self::assertResponseRedirects('http://localhost/login');
        self::assertSame('http://localhost/api/poll', $this->savedTarget());

        $this->signIn();
        self::assertResponseRedirects('/home');
        self::assertNull($this->savedTarget());
    }

    public function testJsonControllerAndHtmlFalseRoutesAreNotTargets(): void
    {
        $this->navigate('/chat/feed');
        $this->signIn();
        self::assertResponseRedirects('/home');

        $this->client->request('GET', '/logout');
        $this->navigate('/reports/export');
        $this->signIn();
        self::assertResponseRedirects('/home');
    }

    public function testHostVoterAllowsAPagePathTheRulesWouldRefuse(): void
    {
        $this->navigate('/docs/api');
        $this->signIn();
        self::assertResponseRedirects('http://localhost/docs/api');
    }

    public function testFullyAuthenticatedDenialStaysA403AndPagesStillRedirect(): void
    {
        $this->signIn();
        self::assertResponseRedirects('/home');

        $this->fetch('/admin/panel');
        self::assertResponseStatusCodeSame(403);

        $this->fetch('/api/poll');
        self::assertResponseIsSuccessful();
    }

    /**
     * FrankenPHP worker mode without kernel reset: one kept-alive kernel, sign-ins alternating between a page target and
     * a machine target. Each answer depends only on its own session: nothing leaks between rounds. (Flat memory is
     * asserted on the listeners themselves in the unit tests; the debug test kernel collects logs per request.).
     */
    public function testKeptAliveKernelKeepsNoStateBetweenSignIns(): void
    {
        for ($round = 0; $round < 120; ++$round) {
            $this->client->restart();
            if ($round % 2 === 0) {
                $this->navigate('/secret');
                $this->fetch('/api/poll');
                self::assertResponseStatusCodeSame(401);
                $this->signIn();
                self::assertResponseRedirects('http://localhost/secret');
            } else {
                $this->legacyNavigate('/chat/feed');
                $this->signIn();
                self::assertResponseRedirects('/home');
            }
        }
    }
}
