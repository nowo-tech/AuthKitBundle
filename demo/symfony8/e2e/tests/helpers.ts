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
