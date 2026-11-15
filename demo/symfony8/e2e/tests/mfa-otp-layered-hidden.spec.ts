import { test, expect, type Page } from '@playwright/test';
import { ensureDemoUser } from './helpers';
import { totp } from './totp';

/**
 * AuthKit 1.51.1 — sign-in challenge and forced setup on a page whose CSS framework hides `[hidden]` with a layered
 * !important (Tailwind 4 preflight, as in Beacon): once otp-input.js has bound them the OTP boxes are visible and take
 * the code. (1.51.0 showed neither the boxes nor the text input there.)
 */
const BOB_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
const PASSWORD = 'demo-password-123';
const TAILWIND_HIDDEN = '@layer base { [hidden]:where(:not([hidden="until-found"])) { display: none !important; } }';

async function withLayeredHidden(page: Page): Promise<void> {
  await page.addInitScript((css) => {
    document.addEventListener('DOMContentLoaded', () => {
      const style = document.createElement('style');
      style.textContent = css;
      document.head.append(style);
    });
  }, TAILWIND_HIDDEN);
}

async function signIn(page: Page, email: string): Promise<void> {
  await page.goto('/en/login');
  await page.locator('input[name="login_form[_username]"]').fill(email);
  await page.locator('input[name="login_form[_password]"]').fill(PASSWORD);
  await page.locator('form:has(input[name="login_form[_username]"]) button[type="submit"]').click();
}

test.describe('MFA code boxes under a layered [hidden] rule', () => {
  test.describe.configure({ mode: 'serial', timeout: 120_000 });

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await ensureDemoUser(page);
    expect((await page.request.post('/_demo/seed')).ok()).toBeTruthy();
    await page.close();
  });

  test('challenge: the boxes show and sign in', async ({ page }) => {
    await withLayeredHidden(page);
    await signIn(page, 'bob@example.com');
    await expect(page).toHaveURL(/\/login\/mfa$/);
    const box = page.locator('form[name="mfa_code_form"] [data-nowo-otp-digit="0"]');
    await expect(box).toBeVisible();
    await expect(page.locator('input[name="mfa_code_form[code]"]')).toHaveAttribute('type', 'hidden');
    await box.fill(totp(BOB_SECRET));
    await page.locator('[data-testid="auth-kit-mfa_challenge"] button[type="submit"]').click();
    await expect(page).toHaveURL(/\/en\/home$/);
  });

  test('forced setup: the boxes show and enrol', async ({ page }) => {
    await withLayeredHidden(page);
    await signIn(page, 'frank@example.com');
    await expect(page).toHaveURL(/\/login\/mfa\/setup$/);
    const box = page.locator('form[name="mfa_code_form"] [data-nowo-otp-digit="0"]');
    await expect(box).toBeVisible();
    const secret = ((await page.locator('[data-testid="auth-kit-mfa-secret"]').textContent()) ?? '').replace(/\s/g, '');
    await box.fill(totp(secret));
    await page.locator('form[name="mfa_code_form"] button[type="submit"]').click();
    await expect(page.locator('[data-testid="auth-kit-mfa-codes"] li')).toHaveCount(10);
  });
});
