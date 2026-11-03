import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Page } from '@playwright/test';
import { ensureDemoUser } from './helpers';
import { totp } from './totp';

/**
 * AuthKit 1.34 — two-step verification and several accounts at once (Beacon D-20261103-05 / -07), with axe checks:
 * enrol with a QR code, challenge, add a second account with MFA, switch without a password, revoke one account,
 * sign out one / all, and a disabled account refused by the switch.
 */
const BOB_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
const PASSWORD = 'demo-password-123';
let step = 0;

/** A fresh TOTP code for each use (a used time step is refused): walk forward inside the ±1 window, then wait. */
async function nextCode(secret: string): Promise<string> {
  step = step >= 1 ? -1 : step + 1;
  if (step === -1) {
    await new Promise((resolve) => setTimeout(resolve, 31_000));
    step = 0;
  }
  return totp(secret, step);
}

async function expectAccessible(page: Page, what: string): Promise<void> {
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
  expect(results.violations, `${what}: ${results.violations.map((v) => v.id).join(', ')}`).toEqual([]);
}

async function signIn(page: Page, email: string): Promise<void> {
  await page.goto('/en/login');
  await page.locator('input[name="login_form[_username]"]').fill(email);
  await page.locator('input[name="login_form[_password]"]').fill(PASSWORD);
  await page.locator('[data-testid="auth-kit-login"] button[type="submit"]').click();
}

async function passChallenge(page: Page): Promise<void> {
  await expect(page.locator('[data-testid="auth-kit-mfa_challenge"]')).toBeVisible();
  await page.locator('input[name="mfa_code_form[code]"]').fill(await nextCode(BOB_SECRET));
  await page.locator('[data-testid="auth-kit-mfa_challenge"] button[type="submit"]').click();
  await expect(page).toHaveURL(/\/en\/home$/);
}

function accounts(page: Page) {
  return page.locator('[data-testid="demo-accounts"] [data-testid="auth-kit-account"]');
}

test.describe('MFA and several accounts', () => {
  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await ensureDemoUser(page);
    const response = await page.request.post('/_demo/seed');
    expect(response.ok()).toBeTruthy();
    await page.close();
  });

  test('a half-authenticated sign-in reaches only the challenge, which is accessible', async ({ page }) => {
    await signIn(page, 'bob@example.com');
    await expect(page).toHaveURL(/\/login\/mfa$/);
    await expectAccessible(page, 'challenge');
    await page.goto('/en/home');
    await expect(page).toHaveURL(/\/login\/mfa$/);
    await page.locator('input[name="mfa_code_form[code]"]').fill('000000');
    await page.locator('[data-testid="auth-kit-mfa_challenge"] button[type="submit"]').click();
    await expect(page.locator('[data-testid="auth-kit-mfa_challenge"] [role="alert"]')).toBeVisible();
    await passChallenge(page);
  });

  test('enrol on the account page with a server-rendered QR code', async ({ page }) => {
    await signIn(page, 'erin@example.com');
    await expect(page).toHaveURL(/\/en\/home$/);
    await page.locator('[data-testid="demo-mfa"]').click();
    await expect(page.locator('[data-testid="auth-kit-mfa-qr"]')).toHaveAttribute('src', /^data:image\//);
    await expectAccessible(page, 'account page');
    const secret = ((await page.locator('[data-testid="auth-kit-mfa-secret"]').textContent()) ?? '').replace(/\s/g, '');
    await page.locator('input[name="mfa_enable[code]"]').fill(totp(secret));
    await page.locator('form[name="mfa_enable"] button[type="submit"]').click();
    await expect(page.locator('[data-testid="auth-kit-mfa-codes"] li')).toHaveCount(10);
  });

  test('add a second account with MFA, switch without a password, sign out one and all', async ({ page }) => {
    await signIn(page, 'carol@example.com');
    await expect(page).toHaveURL(/\/en\/home$/);
    await page.locator('[data-testid="auth-kit-account-add"]').click();
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.locator('[data-testid="auth-kit-login-accounts"]')).toContainText('c•••@example.com');
    await expectAccessible(page, 'sign-in page with accounts');

    await signIn(page, 'bob@example.com');
    await passChallenge(page);
    await expect(accounts(page)).toHaveCount(2);
    await expect(accounts(page).first()).toHaveAttribute('data-state', 'active');
    await expect(page.locator('main')).toContainText('bob@example.com');

    await accounts(page).filter({ hasText: 'carol@example.com' }).locator('[data-testid="auth-kit-account-switch"]').click();
    await expect(page).toHaveURL(/\/en\/home$/);
    await expect(page.locator('main')).toContainText('carol@example.com');
    await expect(page.locator('[data-testid="auth-kit-mfa_challenge"]')).toHaveCount(0);

    await page.goto('/en/accounts');
    await expectAccessible(page, 'chooser');
    await page.goto('/en/home');
    await accounts(page).filter({ hasText: 'bob@example.com' }).locator('[data-testid="auth-kit-account-sign-out"]').click();
    await page.goto('/en/home');
    await expect(accounts(page)).toHaveCount(1);

    await page.locator('[data-testid="auth-kit-account-add"]').click();
    await signIn(page, 'bob@example.com');
    await passChallenge(page);
    await page.locator('[data-testid="auth-kit-account-sign-out-all"]').click();
    await page.goto('/en/home');
    await expect(page).toHaveURL(/\/login$/);
  });

  test('revoking one account elsewhere drops only that account', async ({ page, browser }) => {
    await signIn(page, 'carol@example.com');
    await page.locator('[data-testid="auth-kit-account-add"]').click();
    await signIn(page, 'bob@example.com');
    await passChallenge(page);
    await expect(accounts(page)).toHaveCount(2);

    // Bob signs out "all other sessions" from another browser.
    const other = await browser.newPage();
    await signIn(other, 'bob@example.com');
    await passChallenge(other);
    await other.goto('/account/sessions');
    await other.locator('form[action*="revoke-others"] button[type="submit"]').click();
    const confirm = other.locator('dialog[open] button[type="submit"], [data-nowo-ui-confirm] button[type="submit"]');
    if (await confirm.count()) {
      await confirm.first().click();
    }
    await other.close();

    await page.goto('/en/home');
    await expect(page).toHaveURL(/\/en\/(home|login)/);
    await page.goto('/en/accounts');
    await expect(page.locator('[data-testid="auth-kit-account"]').filter({ hasText: 'bob' })).toHaveCount(0);
    await expect(page.locator('[data-testid="auth-kit-account"]').filter({ hasText: 'c•••@example.com' }).or(page.locator('[data-testid="auth-kit-account"]').filter({ hasText: 'carol@example.com' }))).toHaveCount(1);
  });

  test('a disabled account is refused by the switch', async ({ page }) => {
    await signIn(page, 'dave@example.com');
    await expect(page).toHaveURL(/\/en\/home$/);
    await page.locator('[data-testid="auth-kit-account-add"]').click();
    await signIn(page, 'carol@example.com');
    await expect(page).toHaveURL(/\/en\/home$/);
    expect((await page.request.post('/_demo/disable/dave@example.com')).ok()).toBeTruthy();
    await accounts(page).filter({ hasText: 'dave@example.com' }).locator('[data-testid="auth-kit-account-switch"]').click();
    await expect(page.locator('body')).toContainText('This account is disabled.');
    await expect(accounts(page).or(page.locator('[data-testid="auth-kit-account"]'))).toHaveCount(1);
  });
});
