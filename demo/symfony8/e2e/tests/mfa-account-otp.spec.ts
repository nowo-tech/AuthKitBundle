import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Page } from '@playwright/test';
import { ensureDemoUser } from './helpers';
import { totp } from './totp';

/**
 * AuthKit 1.51.1 (1.52: OtpInputBundle ^1.9 widget, no AuthKit theme) — the MFA account page through a host template (extends @!NowoAuthKit/mfa/account.html.twig) on the
 * admin layout: the code field is always visible, as OTP boxes once otp-input.js has bound them or as one text input
 * otherwise, whatever CSS the host loads (Beacon v1.158: Tailwind 4's layered `[hidden] { display: none !important }`
 * hid both in 1.51.0).
 */
const PASSWORD = 'demo-password-123';
// Tailwind 4 preflight: a layered !important beats any unlayered rule, !important or not.
const TAILWIND_HIDDEN = '@layer base { [hidden]:where(:not([hidden="until-found"])) { display: none !important; } }';

async function openAccount(page: Page, setup?: (page: Page) => Promise<void>): Promise<void> {
  if (setup) {
    await setup(page);
  }
  await page.goto('/en/login');
  await page.locator('input[name="login_form[_username]"]').fill('erin@example.com');
  await page.locator('input[name="login_form[_password]"]').fill(PASSWORD);
  await page.locator('form:has(input[name="login_form[_username]"]) button[type="submit"]').click();
  await expect(page).toHaveURL(/\/en\/home$/);
  await page.locator('[data-testid="demo-mfa"]').click();
  await expect(page.locator('[data-testid="demo-account-nav"]')).toBeVisible();
}

function text(page: Page) {
  return page.locator('form[name="mfa_enable"] input[name="mfa_enable[code]"]');
}

function firstBox(page: Page) {
  return page.locator('form[name="mfa_enable"] [data-nowo-otp-digit="0"]');
}

async function expectAccessible(page: Page, what: string): Promise<void> {
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).exclude('.sf-toolbar').exclude('.sf-minitoolbar').analyze();
  expect(results.violations, `${what}: ${results.violations.map((v) => v.id).join(', ')}`).toEqual([]);
}

test.describe('MFA account page code field (host template, admin layout)', () => {
  test.describe.configure({ mode: 'serial', timeout: 120_000 });

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await ensureDemoUser(page);
    expect((await page.request.post('/_demo/seed')).ok()).toBeTruthy();
    await page.close();
  });

  test('scripts and stylesheet loaded: the boxes', async ({ page }) => {
    await openAccount(page);
    await expect(firstBox(page)).toBeVisible();
    await expect(text(page)).toHaveAttribute('type', 'hidden');
  });

  test('a framework hiding [hidden] with a layered !important: still the boxes', async ({ page }) => {
    await openAccount(page, async (p) => {
      await p.addInitScript((css) => {
        document.addEventListener('DOMContentLoaded', () => {
          const style = document.createElement('style');
          style.textContent = css;
          document.head.append(style);
        });
      }, TAILWIND_HIDDEN);
    });
    await expect(firstBox(page)).toBeVisible();
    await expectAccessible(page, 'account page, layered [hidden]');
  });

  test('AuthKit stylesheet not loaded: the boxes', async ({ page }) => {
    await openAccount(page, (p) => p.route('**/nowo-auth-kit.css', (route) => route.abort()));
    await expect(firstBox(page)).toBeVisible();
    await expect(text(page)).toBeHidden();
  });

  test('AuthKit script blocked (only otp-input.js): the boxes (1.52: OtpInputBundle 1.9 reveals them itself)', async ({ page }) => {
    await openAccount(page, (p) => p.route('**/nowo-auth-kit-otp.js', (route) => route.abort()));
    await expect(firstBox(page)).toBeVisible();
    await expect(text(page)).toHaveAttribute('type', 'hidden');
    await expect(page.locator('[data-nowo-otp-digit="0"]')).toHaveCount(1);
  });

  test('no OTP scripts (blocked, or a host without _otp_input_assets) and no stylesheet: the text input enrols', async ({ page }) => {
    await openAccount(page, async (p) => {
      await p.route('**/otp-input.js', (route) => route.abort());
      await p.route('**/nowo-auth-kit-otp.js', (route) => route.abort());
      await p.route('**/nowo-auth-kit.css', (route) => route.abort());
      await p.addInitScript((css) => {
        document.addEventListener('DOMContentLoaded', () => {
          const style = document.createElement('style');
          style.textContent = css;
          document.head.append(style);
        });
      }, TAILWIND_HIDDEN);
    });
    await expect(text(page)).toBeVisible();
    await expect(text(page)).toHaveAttribute('type', 'text');
    await expect(firstBox(page)).toBeHidden();
    await expectAccessible(page, 'account page, no scripts');
    const secret = ((await page.locator('[data-testid="auth-kit-mfa-secret"]').textContent()) ?? '').replace(/\s/g, '');
    await text(page).fill(totp(secret));
    await page.locator('form[name="mfa_enable"] button[type="submit"]').click();
    await expect(page.locator('[data-testid="auth-kit-mfa-codes"] li')).toHaveCount(10);
  });

  test.describe('JavaScript disabled', () => {
    test.use({ javaScriptEnabled: false });

    test('the text input', async ({ page }) => {
      await page.goto('/en/login');
      await page.locator('input[name="login_form[_username]"]').fill('carol@example.com');
      await page.locator('input[name="login_form[_password]"]').fill(PASSWORD);
      await page.locator('input[name="login_form[_password]"]').press('Enter');
      await expect(page).toHaveURL(/\/en\/home$/);
      await page.goto('/en/account/security/mfa');
      await expect(page.locator('[data-testid="demo-account-nav"]')).toBeVisible();
      await expect(text(page)).toBeVisible();
      await expect(firstBox(page)).toBeHidden();
    });
  });
});
