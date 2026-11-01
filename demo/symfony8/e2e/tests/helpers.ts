import { expect, type Page } from '@playwright/test';

export const DEMO_EMAIL = 'ada@example.com';
export const DEMO_PASSWORD = 'demo-password-123';

/**
 * The demo starts without users (registration_mode first_user_only): registers the demo account when the
 * register page is still open, then signs out so the guest pages render.
 */
export async function ensureDemoUser(page: Page): Promise<void> {
  await page.goto('/en/register');
  if (await page.locator('[data-testid="auth-kit-register"]').count()) {
    await page.locator('input[name="registration_form[email]"]').fill(DEMO_EMAIL);
    await page.locator('input[name="registration_form[password][first]"]').fill(DEMO_PASSWORD);
    await page.locator('input[name="registration_form[password][second]"]').fill(DEMO_PASSWORD);
    await page.locator('[data-testid="auth-kit-register"] button[type="submit"]').click();
    await expect(page).toHaveURL(/\/en\/home$/);
  }
  await page.context().clearCookies();
}

/** Signs the demo account in (the first demo account is the administrator: invitations.security.access_roles). */
export async function signIn(page: Page): Promise<void> {
  await page.goto('/en/login');
  await page.locator('input[name="login_form[_username]"]').fill(DEMO_EMAIL);
  await page.locator('input[name="login_form[_password]"]').fill(DEMO_PASSWORD);
  await page.locator('[data-testid="auth-kit-login"] button[type="submit"]').click();
  await expect(page).toHaveURL(/\/en\/home$/);
}

/**
 * Invites `email` from the administration and returns the path of the single-use link. The demo has no mailer, so
 * the link is shown once (invitations.show_link_when_undelivered); only its path is used (the origin is DEFAULT_URI).
 */
export async function inviteAndReadLink(page: Page, email: string): Promise<string> {
  await page.goto('/en/admin/invitations/new');
  const form = page.locator('[data-testid="nowo-auth-kit-invitation-new"] form');
  await form.locator('input[name="invitation[email]"]').fill(email);
  await form.locator('textarea[name="invitation[message]"]').fill('Welcome to the demo team!');
  await form.locator('button[type="submit"]').click();
  const linkPage = page.locator('[data-testid="nowo-auth-kit-invitation-link"]');
  await expect(linkPage).toBeVisible();
  const text = (await linkPage.textContent()) ?? '';
  const match = text.match(/https?:\/\/[^\s]+\/invitation\/[A-Za-z0-9_-]{43}/);
  expect(match, 'the invitation link is shown once').not.toBeNull();

  return new URL(match![0]).pathname;
}
