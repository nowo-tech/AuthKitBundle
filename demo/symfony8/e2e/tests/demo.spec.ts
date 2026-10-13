import { test, expect } from '@playwright/test';

/**
 * REQ-DEMO-013 — happy-path e2e against the AuthKit demo UI.
 */
test.describe('AuthKit demo happy path', () => {
  test('welcome page renders with locale switcher and Account dropdown', async ({ page }) => {
    const response = await page.goto('/en');
    expect(response?.ok()).toBeTruthy();
    await expect(page.locator('h1')).toContainText('Welcome');
    await expect(page.locator('.locale-switcher')).toBeVisible();
    await expect(page.locator('.nowo-auth-kit__embed-toggle')).toBeVisible();
  });

  test('embedded sign-in opens in the navbar dropdown', async ({ page }) => {
    await page.goto('/en');
    await page.locator('.nowo-auth-kit__embed-toggle').click();
    const menu = page.locator('.nowo-auth-kit__embed-menu');
    await expect(menu).toBeVisible();
    await expect(menu.locator('form[name="login_form"]')).toBeVisible();
  });

  test('full-page login and bare URL redirect', async ({ page }) => {
    const response = await page.goto('/en/login');
    expect(response?.ok()).toBeTruthy();
    await expect(page.locator('form[name="login_form"]').first()).toBeVisible();

    await page.goto('/login');
    await expect(page).toHaveURL(/\/en\/login$/);
  });

  test('Spanish login and password reset request render', async ({ page }) => {
    expect((await page.goto('/es/login'))?.ok()).toBeTruthy();
    expect((await page.goto('/en/reset-password'))?.ok()).toBeTruthy();
    await expect(page.locator('form').first()).toBeVisible();
  });
});
