import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Page } from '@playwright/test';
import { ensureDemoUser } from './helpers';
import { totp } from './totp';

/**
 * AuthKit 1.51 (audit QV-M1) — the MFA code on OTP boxes still works when otp-input.js does not run: JavaScript off
 * (challenge and forced setup) or the script blocked (challenge, with axe). The field is then one visible text input.
 *
 * 1.52 (Beacon e2e mfa-code-field, OtpInputBundle 1.9): the code field is OtpInputBundle's own widget, rendered once:
 * one set of boxes (hidden until otp-input.js binds them), one named input, no duplicate id — with JavaScript on,
 * off, and with otp-input.js blocked. Each sign-in re-seeds the demo so bob's code is never a replay.
 */
const BOB_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
const PASSWORD = 'demo-password-123';

async function signIn(page: Page, email: string): Promise<void> {
  await page.goto('/en/login');
  await page.locator('input[name="login_form[_username]"]').fill(email);
  await page.locator('input[name="login_form[_password]"]').fill(PASSWORD);
  await page.locator('form:has(input[name="login_form[_username]"]) button[type="submit"]').click();
}

/** One OtpInput widget on the page: one set of boxes, one named code input, every id unique. */
async function expectOneCodeField(page: Page): Promise<void> {
  await expect(page.locator('[data-nowo-otp-boxes]')).toHaveCount(1);
  await expect(page.locator('[data-nowo-otp-digit="0"]')).toHaveCount(1);
  await expect(page.locator('#mfa_code_form_code_digit_0')).toHaveCount(1);
  await expect(page.locator('[data-nowo-otp-digit]')).toHaveCount(6);
  await expect(page.locator('input[name="mfa_code_form[code]"]')).toHaveCount(1);
  const ids = await page.locator('[id]').evaluateAll((nodes) => nodes.map((node) => node.id));
  expect(ids.filter((id, index) => ids.indexOf(id) !== index), 'duplicate ids').toEqual([]);
}

test.describe('MFA code field: JavaScript on, off, and otp-input.js blocked', () => {
  test.describe.configure({ mode: 'serial', timeout: 120_000 });

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await ensureDemoUser(page);
    await page.close();
  });

  test.beforeEach(async ({ request }) => {
    expect((await request.post('/_demo/seed')).ok()).toBeTruthy();
  });

  test('JavaScript on: the boxes show, the named field is hidden, the code signs in', async ({ page }) => {
    await signIn(page, 'bob@example.com');
    await expect(page).toHaveURL(/\/login\/mfa$/);
    await expectOneCodeField(page);
    const box = page.locator('[data-nowo-otp-digit="0"]');
    await expect(box).toBeVisible();
    const code = page.locator('input[name="mfa_code_form[code]"]');
    await expect(code).toHaveAttribute('type', 'hidden');
    await expect(page.locator('nowo-otp-input.nowo-auth-kit-mfa-otp')).toHaveAttribute('data-nowo-otp-init', '1');
    await box.fill(totp(BOB_SECRET));
    await expect(code).toHaveValue(totp(BOB_SECRET));
    await page.locator('[data-testid="auth-kit-mfa_challenge"] button[type="submit"]').click();
    await expect(page).toHaveURL(/\/en\/home$/);
  });

  test('the script blocked: one accessible text input, no boxes, the code signs in', async ({ page }) => {
    await page.route('**/otp-input.js', (route) => route.abort());
    await signIn(page, 'bob@example.com');
    await expect(page).toHaveURL(/\/login\/mfa$/);
    await expectOneCodeField(page);
    const code = page.locator('input[name="mfa_code_form[code]"]');
    await expect(code).toBeVisible();
    await expect(code).toHaveAttribute('type', 'text');
    await expect(page.locator('[data-nowo-otp-digit]').first()).toBeHidden();
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).exclude('.sf-toolbar').exclude('.sf-minitoolbar').analyze();
    expect(results.violations, results.violations.map((v) => v.id).join(', ')).toEqual([]);
    await code.fill(totp(BOB_SECRET));
    await page.locator('[data-testid="auth-kit-mfa_challenge"] button[type="submit"]').click();
    await expect(page).toHaveURL(/\/en\/home$/);
  });

  test.describe('JavaScript disabled', () => {
    test.use({ javaScriptEnabled: false });

    test('the challenge: one set of boxes, hidden; the text input posts the code', async ({ page }) => {
      await signIn(page, 'bob@example.com');
      await expect(page).toHaveURL(/\/login\/mfa$/);
      await expectOneCodeField(page);
      await expect(page.locator('[data-nowo-otp-digit]').first()).toBeHidden();
      const code = page.locator('input[name="mfa_code_form[code]"]');
      await expect(code).toBeVisible();
      await expect(code).toHaveAttribute('type', 'text');
      await code.fill(totp(BOB_SECRET));
      await page.locator('[data-testid="auth-kit-mfa_challenge"] button[type="submit"]').click();
      await expect(page).toHaveURL(/\/en\/home$/);
    });

    test('the forced setup takes the code in the text input', async ({ page }) => {
      await signIn(page, 'frank@example.com');
      await expect(page).toHaveURL(/\/login\/mfa\/setup$/);
      await expectOneCodeField(page);
      await expect(page.locator('[data-nowo-otp-digit]').first()).toBeHidden();
      const secret = ((await page.locator('[data-testid="auth-kit-mfa-secret"]').textContent()) ?? '').replace(/\s/g, '');
      // Enter submits the form natively (the dev toolbar, expanded without JavaScript, covers the button).
      await page.locator('input[name="mfa_code_form[code]"]').fill(totp(secret));
      await page.locator('input[name="mfa_code_form[code]"]').press('Enter');
      await expect(page.locator('[data-testid="auth-kit-mfa-codes"] li')).toHaveCount(10);
    });
  });
});
