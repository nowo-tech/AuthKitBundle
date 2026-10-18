import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import type { Page } from '@playwright/test';
import { DEMO_EMAIL, ensureDemoUser } from './helpers';

/**
 * REQ-DEMO-013 — the auth pages in the demo frame (demo navbar + UiKit auth scaffold + Symfony toolbar), in useful
 * states: sign-in, password reset with a validation error, QR phone sign-in.
 */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

const WIDTH = 1280;
const PAD = 16;
const CHROME = 'nav.navbar, main';

/** Grows the viewport so the page fits above the WebProfiler toolbar (which must have finished loading). */
async function fitViewport(page: Page): Promise<void> {
  const toolbar = page.locator('.sf-toolbar .sf-toolbarreset');
  await expect(toolbar).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('.sf-toolbar').first()).not.toContainText('Loading', { timeout: 15_000 });
  const toolbarHeight = Math.ceil((await toolbar.boundingBox())?.height ?? 36);
  const contentBottom = await page.evaluate((sel) => Math.max(...[...document.querySelectorAll(sel)]
    .filter((el) => !el.closest('.sf-toolbar'))
    .map((el) => el.getBoundingClientRect().bottom + window.scrollY)), CHROME);
  await page.setViewportSize({ width: WIDTH, height: Math.max(720, Math.ceil(contentBottom) + 24 + toolbarHeight) });
  await page.evaluate(() => window.scrollTo(0, 0));
}

/** Padded union clip of the demo chrome and the toolbar. */
async function shoot(page: Page, file: string): Promise<void> {
  await expect(page.locator('.sf-toolbar').first()).not.toContainText('Loading');
  const box = await page.evaluate((sel) => {
    const rects = [...document.querySelectorAll(`${sel}, .sf-toolbar .sf-toolbarreset`)]
      .map((el) => el.getBoundingClientRect())
      .filter((r) => r.width > 0 && r.height > 0);
    return {
      x: Math.min(...rects.map((r) => r.left)),
      y: Math.min(...rects.map((r) => r.top)),
      r: Math.max(...rects.map((r) => r.right)),
      b: Math.max(...rects.map((r) => r.bottom)),
    };
  }, CHROME);
  const vp = page.viewportSize()!;
  const x = Math.max(0, box.x - PAD);
  const y = Math.max(0, box.y - PAD);
  await page.screenshot({
    path: resolve(outDir, `${file}.png`),
    clip: { x, y, width: Math.min(vp.width, box.r + PAD) - x, height: Math.min(vp.height, box.b + PAD) - y },
  });
}

test.use({ viewport: { width: WIDTH, height: 900 } });

test.beforeAll(async ({ browser }) => {
  mkdirSync(outDir, { recursive: true });
  const page = await browser.newPage();
  await ensureDemoUser(page);
  await page.close();
});

test.describe('AuthKit demo screenshots', () => {
  test('sign-in page', async ({ page }) => {
    await page.goto('/en/login');
    await expect(page.locator('[data-testid="auth-kit-login"]')).toBeVisible();
    await page.locator('input[name="login_form[_username]"]').fill(DEMO_EMAIL);
    await fitViewport(page);
    await shoot(page, 'login');
  });

  test('password reset with a validation error', async ({ page }) => {
    await page.goto('/en/reset-password');
    await page.locator('input[name="reset_password_request_form[identifier]"]').fill(DEMO_EMAIL);
    await page.locator('[data-testid="auth-kit-reset_request"] button[type="submit"]').click();
    await page.locator('a', { hasText: 'Open reset link' }).first().click();
    const panel = page.locator('[data-testid="auth-kit-reset_password"]');
    await panel.locator('input[name="reset_password_form[password][first]"]').fill('new-password-456');
    await panel.locator('input[name="reset_password_form[password][second]"]').fill('another-one-789');
    await panel.locator('button[type="submit"]').click();
    await expect(panel).toContainText('The password fields must match.');
    await fitViewport(page);
    await shoot(page, 'reset-password-error');
  });

  test('QR phone sign-in', async ({ page }) => {
    await page.goto('/en/login/qr');
    await expect(page.locator('img.nowo-auth-kit-qr__image')).toBeVisible();
    await fitViewport(page);
    await shoot(page, 'qr-login');
  });
});
