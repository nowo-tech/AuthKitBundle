import { test, expect } from '@playwright/test';
import { ensureDemoUser, inviteAndReadLink, signIn } from './helpers';

/**
 * REQ-DEMO-013 — invitations (AuthKit 1.32): an administrator invites an e-mail, the link is shown once (no mailer in
 * the demo), the invitee opens it in a fresh browser context and creates the account on the registration form with
 * the e-mail locked, and the same link then answers the neutral "no longer valid" page.
 */
test.describe('AuthKit demo invitations', () => {
  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await ensureDemoUser(page);
    await page.close();
  });

  test('invite, accept with the e-mail locked, single use', async ({ page, browser }) => {
    const email = `invitee-${Date.now()}@example.com`;
    await signIn(page);
    const path = await inviteAndReadLink(page, email);

    // The list shows the pending invitation with its row actions.
    await page.goto('/en/admin/invitations');
    const row = page.locator('tr', { has: page.locator('[data-testid="nowo-auth-kit-invitation-email"]', { hasText: email }) });
    await expect(row.locator('[data-testid="nowo-auth-kit-invitation-status"]')).toHaveAttribute('data-status', 'pending');

    // Fresh context: the invitee is not signed in.
    const invitee = await browser.newContext();
    const tab = await invitee.newPage();
    const opened = await tab.goto(path);
    expect(opened?.ok()).toBeTruthy();
    await expect(tab).toHaveURL(/\/en\/invitation$/, { timeout: 10_000 });
    const panel = tab.locator('[data-testid="auth-kit-invitation_accept"]');
    await expect(panel).toBeVisible();
    await expect(panel.locator('[data-testid="auth-kit-invitation-message"]')).toHaveText('Welcome to the demo team!');
    const emailField = panel.locator('input[name="registration_form[email]"]');
    await expect(emailField).toHaveValue(email);
    await expect(emailField).toBeDisabled();
    await panel.locator('input[name="registration_form[password][first]"]').fill('invited-password-123');
    await panel.locator('input[name="registration_form[password][second]"]').fill('invited-password-123');
    await panel.locator('button[type="submit"]').click();
    await expect(tab).toHaveURL(/\/en\/home$/);
    await expect(tab.locator('body')).toContainText(email);
    // The invited account is no administrator: the invitation pages stay closed to it.
    const admin = await tab.goto('/en/admin/invitations');
    expect(admin?.status()).toBe(403);
    await invitee.close();

    // The same link again, in another fresh context: the neutral page, without the invitee's e-mail.
    const again = await browser.newContext();
    const second = await again.newPage();
    await second.goto(path);
    const notice = second.locator('[data-nowo-auth-kit-invitation="invalid"]');
    await expect(notice).toBeVisible();
    await expect(notice.locator('h1')).toHaveText('This invitation is no longer valid');
    await expect(second.locator('body')).not.toContainText(email);
    await again.close();

    await page.goto('/en/admin/invitations?status=accepted');
    await expect(page.locator('[data-testid="nowo-auth-kit-invitation-email"]', { hasText: email })).toBeVisible();
  });
});
