import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import type { Page } from '@playwright/test';
import { DEMO_EMAIL, ensureDemoUser, inviteAndReadLink, signIn } from './helpers';

/**
 * REQ-DEMO-013 — the README screenshots (1–3), in the demo platform shell (UiKit scaffold, no Symfony toolbar):
 * sign-in, the invitation administration list and the accept page.
 */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

const WIDTH = 1280;
const PAD = 16;
const SHELL = '[data-testid="demo-platform"]';

/** Hides the Symfony Web Debug Toolbar: the README screenshots show the demo platform shell only. */
async function hideProfiler(page: Page): Promise<void> {
  await page.addStyleTag({ content: '.sf-toolbar,.sf-minitoolbar,[id^="sfwdt"]{display:none!important}' });
}

/** Grows the viewport so the whole page fits (the toolbar is hidden). */
async function fitViewport(page: Page): Promise<void> {
  await hideProfiler(page);
  await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {});
  await page.setViewportSize({ width: WIDTH, height: 720 });
  const height = await page.evaluate(() => document.documentElement.scrollHeight);
  await page.setViewportSize({ width: WIDTH, height: Math.min(Math.max(720, height), 1800) });
  await page.evaluate(() => window.scrollTo(0, 0));
}

/** Padded clip of the demo platform shell. */
async function shoot(page: Page, file: string): Promise<void> {
  const box = (await page.locator(SHELL).boundingBox())!;
  const vp = page.viewportSize()!;
  const x = Math.max(0, box.x - PAD);
  const y = Math.max(0, box.y - PAD);
  await page.screenshot({
    path: resolve(outDir, `${file}.png`),
    clip: { x, y, width: Math.min(vp.width, box.x + box.width + PAD) - x, height: Math.min(vp.height, box.y + box.height + PAD) - y },
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

  test('invitations: administration list and accept page', async ({ page, browser }) => {
    await signIn(page);
    const stamp = Date.now();
    await inviteAndReadLink(page, `grace-${stamp}@example.com`);
    const path = await inviteAndReadLink(page, `alan-${stamp}@example.com`);
    await page.goto('/en/admin/invitations');
    await expect(page.locator('[data-testid="nowo-auth-kit-invitations"]')).toBeVisible();
    await fitViewport(page);
    await shoot(page, 'invitations-list');

    const invitee = await browser.newContext({ viewport: { width: WIDTH, height: 900 } });
    const tab = await invitee.newPage();
    await tab.goto(path);
    await expect(tab.locator('[data-testid="auth-kit-invitation_accept"]')).toBeVisible();
    await fitViewport(tab);
    await shoot(tab, 'invitation-accept');
    await invitee.close();
  });
});
