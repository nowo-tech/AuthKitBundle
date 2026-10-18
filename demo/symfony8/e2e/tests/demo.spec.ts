import { test, expect } from '@playwright/test';
import { DEMO_EMAIL, DEMO_PASSWORD, ensureDemoUser } from './helpers';

/**
 * REQ-DEMO-013 — happy-path e2e against the AuthKit demo: auth pages on the UiKit auth scaffold (guest layout of
 * the demo host), embedded Account widget, login errors, password reset, QR challenge.
 */
test.describe('AuthKit demo happy path', () => {
  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await ensureDemoUser(page);
    await page.close();
  });

  test('welcome page renders with locale switcher and Account widget', async ({ page }) => {
    const response = await page.goto('/en');
    expect(response?.ok()).toBeTruthy();
    await expect(page.locator('h1')).toContainText('Welcome');
    await expect(page.locator('.locale-switcher')).toBeVisible();
    await expect(page.locator('.nowo-auth-kit-embed__toggle')).toBeVisible();
  });

  test('embedded sign-in opens from the navbar', async ({ page }) => {
    await page.goto('/en');
    await page.locator('.nowo-auth-kit-embed__toggle').click();
    const menu = page.locator('.nowo-auth-kit-embed__menu');
    await expect(menu).toBeVisible();
    await expect(menu.locator('form[name="login_form"]')).toBeVisible();
  });

  test('login page is the UiKit auth scaffold and shows the credentials error', async ({ page }) => {
    const response = await page.goto('/en/login');
    expect(response?.ok()).toBeTruthy();
    const panel = page.locator('[data-nowo-ui-page="auth"][data-testid="auth-kit-login"]');
    await expect(panel).toBeVisible();
    await expect(panel.locator('h1')).toHaveText('Sign in');
    await panel.locator('input[name="login_form[_username]"]').fill(DEMO_EMAIL);
    await panel.locator('input[name="login_form[_password]"]').fill('wrong-password');
    await panel.locator('button[type="submit"]').click();
    await expect(panel.locator('[role="alert"]')).toHaveText('Invalid credentials.');

    await panel.locator('input[name="login_form[_password]"]').fill(DEMO_PASSWORD);
    await panel.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/en\/home$/);
  });

  test('bare URL redirects and the Spanish login renders', async ({ page }) => {
    await page.goto('/login');
    await expect(page).toHaveURL(/\/en\/login$/);
    expect((await page.goto('/es/login'))?.ok()).toBeTruthy();
    await expect(page.locator('[data-testid="auth-kit-login"] h1')).toHaveText('Iniciar sesión');
  });

  test('password reset by link shows the mismatch error before the submit button', async ({ page }) => {
    await page.goto('/en/reset-password');
    await page.locator('input[name="reset_password_request_form[identifier]"]').fill(DEMO_EMAIL);
    await page.locator('[data-testid="auth-kit-reset_request"] button[type="submit"]').click();
    await page.locator('a', { hasText: 'Open reset link' }).first().click();
    const panel = page.locator('[data-testid="auth-kit-reset_password"]');
    await expect(panel).toBeVisible();
    await panel.locator('input[name="reset_password_form[password][first]"]').fill('new-password-456');
    await panel.locator('input[name="reset_password_form[password][second]"]').fill('another-one-789');
    await panel.locator('button[type="submit"]').click();
    await expect(panel).toContainText('The password fields must match.');
    const order = await panel.locator('form').evaluate((form) => [...form.querySelectorAll('input[type="password"], input[type="text"], button[type="submit"]')].map((el) => el.getAttribute('name') ?? el.tagName));
    expect(order.at(-1)).toBe('BUTTON');
  });

  test('QR challenge page polls the status', async ({ page }) => {
    await page.goto('/en/login/qr');
    const challenge = page.locator('[data-nowo-auth-kit-qr]');
    await expect(challenge).toHaveAttribute('data-nowo-auth-kit-qr-state', 'pending');
    await expect(challenge.locator('img.nowo-auth-kit-qr__image')).toBeVisible();
    await expect(page.locator('[data-nowo-auth-kit-qr-region="countdown"]')).toHaveText(/^\d+:\d{2}$/);
    await expect(page.locator('[data-nowo-auth-kit-qr-region="status"]')).toHaveText('Waiting for approval…');
  });
});
