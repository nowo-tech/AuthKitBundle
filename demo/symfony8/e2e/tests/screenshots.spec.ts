import { test, expect, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * REQ-DEMO-013 — README screenshots (docs/assets/*.webp after scripts/to-webp.mjs).
 * The AuthKit UI lives in the navbar (Account dropdown) and in the full-page forms,
 * so captures keep the demo navbar + page card and hide the Symfony toolbar.
 */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/assets');

test.use({ viewport: { width: 1024, height: 768 } });

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

async function hideDebugToolbar(page: Page): Promise<void> {
  await page.addStyleTag({ content: '.sf-toolbar, [id^="sfwdt"], .sf-minitoolbar { display: none !important; }' });
}

test.describe('AuthKit demo screenshots', () => {
  test('welcome page with locale switcher and Account dropdown', async ({ page }) => {
    await page.goto('/en');
    await hideDebugToolbar(page);
    await expect(page.locator('h1')).toContainText('Welcome');
    await page.screenshot({ path: resolve(outDir, 'demo-welcome.png') });
  });

  test('full-page login', async ({ page }) => {
    await page.goto('/en/login');
    await hideDebugToolbar(page);
    await expect(page.locator('form[name="login_form"]').first()).toBeVisible();
    await page.screenshot({ path: resolve(outDir, 'demo-login.png') });
  });

  test('embedded sign-in in navbar dropdown', async ({ page }) => {
    await page.goto('/en');
    await hideDebugToolbar(page);
    await page.locator('.nowo-auth-kit__embed-toggle').click();
    await expect(page.locator('.nowo-auth-kit__embed-menu form[name="login_form"]')).toBeVisible();
    await page.screenshot({ path: resolve(outDir, 'demo-embed-dropdown.png') });
  });
});
