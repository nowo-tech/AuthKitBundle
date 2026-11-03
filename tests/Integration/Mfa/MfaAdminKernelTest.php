<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use Nowo\AuthKitBundle\Mfa\Event\MfaResetByAdminEvent;

/**
 * Admin reset (1.34, `mfa.admin.routes`): access roles, CSRF, never the secret, required users enrol at once.
 */
final class MfaAdminKernelTest extends MfaKernelTestCase
{
    public function testSupportResetsAUserFactor(): void
    {
        $this->signIn('admin@example.com', 'admin-password-123');
        [$secret] = $this->enrol();
        $this->client->request('GET', '/logout');

        $this->client->loginUser($this->user('support@example.com'));
        $crawler = $this->client->request('GET', '/admin/mfa/admin@example.com');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="auth-kit-mfa-admin-status"]', 'admin@example.com has two-step verification on.');
        self::assertStringNotContainsString($secret, (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/admin/mfa/admin@example.com', ['csrf_only' => ['_token' => 'forged']]);
        self::assertResponseStatusCodeSame(403);

        $this->client->submit($crawler->filter('[data-testid="auth-kit-mfa-admin"] form')->form());
        self::assertResponseRedirects('/admin/mfa/admin@example.com', 303);
        $reset = $this->eventsOf(MfaResetByAdminEvent::class);
        self::assertCount(1, $reset);
        self::assertSame('support@example.com', $reset[0]->actor->getUserIdentifier());
        self::assertFalse($this->manager()->isEnabled($this->user('admin@example.com')));

        $crawler = $this->client->request('GET', '/admin/mfa/admin@example.com');
        self::assertSelectorTextContains('[data-testid="auth-kit-mfa-admin-status"]', 'off');
        self::assertStringContainsString('Two-step verification of admin@example.com was reset.', $crawler->text());
        self::assertFalse($this->manager()->reset($this->user('admin@example.com'), $this->user('support@example.com')), 'Nothing to reset any more.');

        // The required administrator enrols at the next sign-in (no grace after a reset).
        $this->client->request('GET', '/logout');
        $this->signIn('admin@example.com', 'admin-password-123');
        self::assertResponseRedirects('/login/mfa/setup');
    }

    public function testAccessRules(): void
    {
        $this->client->loginUser($this->user('member@example.com'));
        $this->client->request('GET', '/admin/mfa/admin@example.com');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->user('support@example.com'));
        $this->client->request('GET', '/admin/mfa/nobody@example.com');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/admin/mfa/support@example.com');
        self::assertResponseStatusCodeSame(403, 'Not for one\'s own account.');
    }
}
