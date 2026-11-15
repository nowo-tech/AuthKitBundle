import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Page } from '@playwright/test';
import { ensureDemoUser } from './helpers';
import { totp } from './totp';

/**
 * AuthKit 1.51 (audit QV-M1) — the MFA code on OTP boxes still works when otp-input.js does not run: JavaScript off
 * (challenge and forced setup) or the script blocked (challenge, with axe). The field is then one visible text input.
 */
const BOB_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
const PASSWORD = 'demo-password-123';

async function signIn(page: Page, email: string): Promise<void> {
  await page.goto('/en/login');
  await page.locator('input[name="login_form[_username]"]').fill(email);
  await page.locator('input[name="login_form[_password]"]').fill(PASSWORD);
  await page.locator('form:has(input[name="login_form[_username]"]) button[type="submit"]').click();
}

test.describe('MFA code without a working otp-input.js', () => {
  test.describe.configure({ mode: 'serial', timeout: 120_000 });

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await ensureDemoUser(page);
    expect((await page.request.post('/_demo/seed')).ok()).toBeTruthy();
    await page.close();
  });

  test('the script blocked: one accessible text input, no boxes', async ({ page }) => {
    await page.route('**/otp-input.js', (route) => route.abort());
    await signIn(page, 'bob@example.com');
    await expect(page).toHaveURL(/\/login\/mfa$/);
    const code = page.locator('input[name="mfa_code_form[code]"]');
    await expect(code).toBeVisible();
    await expect(code).toHaveAttribute('type', 'text');
    await expect(page.locator('[data-nowo-otp-digit]').first()).toBeHidden();
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).exclude('.sf-toolbar').exclude('.sf-minitoolbar').analyze();
    expect(results.violations, results.violations.map((v) => v.id).join(', ')).toEqual([]);
  });

  test.describe('JavaScript disabled', () => {
    test.use({ javaScriptEnabled: false });

    test('the challenge takes the code in the text input', async ({ page }) => {
      await signIn(page, 'bob@example.com');
      await expect(page).toHaveURL(/\/login\/mfa$/);
      await expect(page.locator('[data-nowo-otp-digit]').first()).toBeHidden();
      await page.locator('input[name="mfa_code_form[code]"]').fill(totp(BOB_SECRET));
      await page.locator('[data-testid="auth-kit-mfa_challenge"] button[type="submit"]').click();
      await expect(page).toHaveURL(/\/en\/home$/);
    });

    test('the forced setup takes the code in the text input', async ({ page }) => {
      await signIn(page, 'frank@example.com');
      await expect(page).toHaveURL(/\/login\/mfa\/setup$/);
      await expect(page.locator('[data-nowo-otp-digit]').first()).toBeHidden();
      const secret = ((await page.locator('[data-testid="auth-kit-mfa-secret"]').textContent()) ?? '').replace(/\s/g, '');
      // Enter submits the form natively (the dev toolbar, expanded without JavaScript, covers the button).
      await page.locator('input[name="mfa_code_form[code]"]').fill(totp(secret));
      await page.locator('input[name="mfa_code_form[code]"]').press('Enter');
      await expect(page.locator('[data-testid="auth-kit-mfa-codes"] li')).toHaveCount(10);
    });
  });
});
