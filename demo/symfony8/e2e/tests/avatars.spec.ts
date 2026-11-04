import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Page } from '@playwright/test';
import { DEMO_EMAIL, DEMO_PASSWORD, ensureDemoUser } from './helpers';

/**
 * AuthKit 1.35 — avatars in the account switcher through UiKit's avatar partial. The demo presenter gives carol a
 * same-origin picture and ada (the demo administrator) a broken URL. The active account shows its picture over the
 * initials; a broken picture falls back to the initials (js/nowo-ui-avatar.js); the other accounts of the browser show
 * initials only (chooser.other_account_avatars: false). axe stays clean in every state.
 */
async function signIn(page: Page, email: string): Promise<void> {
  await page.goto('/en/login');
  await page.locator('input[name="login_form[_username]"]').fill(email);
  await page.locator('input[name="login_form[_password]"]').fill(DEMO_PASSWORD);
  await page.locator('form:has(input[name="login_form[_username]"]) button[type="submit"]').click();
  await expect(page).toHaveURL(/\/en\/home$/);
}

async function expectAccessible(page: Page, what: string): Promise<void> {
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).exclude('.sf-toolbar').exclude('.sf-minitoolbar').analyze();
  expect(results.violations, `${what}: ${results.violations.map((v) => v.id).join(', ')}`).toEqual([]);
}

const avatarOf = (page: Page, text: string) =>
  page.locator('[data-testid="demo-accounts"] [data-testid="auth-kit-account"]').filter({ hasText: text }).locator('[data-testid="auth-kit-account-avatar"]');

test.describe('switcher avatars (1.35)', () => {
  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await ensureDemoUser(page);
    expect((await page.request.post('/_demo/seed')).ok()).toBeTruthy();
    await page.close();
  });

  test('the active picture loads, a broken one falls back, other accounts show initials', async ({ page }) => {
    const requested: string[] = [];
    page.on('request', (request) => requested.push(new URL(request.url()).pathname));
    const accounts = page.locator('[data-testid="demo-accounts"] [data-testid="auth-kit-account"]');

    // carol signs in, then adds ada: the sign-in page lists carol (another account) with initials only.
    await signIn(page, 'carol@example.com');
    await page.locator('[data-testid="auth-kit-account-add"]').click();
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.locator('[data-testid="auth-kit-account-avatar"]')).toHaveCount(1);
    await expect(page.locator('[data-testid="auth-kit-account-avatar"] img')).toHaveCount(0);
    await expectAccessible(page, 'sign-in page with accounts');

    // ada (broken URL) is active: the picture is requested, fails, and the initials come back; carol shows initials.
    await signIn(page, DEMO_EMAIL);
    await expect(accounts).toHaveCount(2);
    const ada = avatarOf(page, DEMO_EMAIL);
    await expect(ada.locator('img')).toHaveCount(0);
    await expect(ada).not.toHaveClass(/\bhas-image\b/);
    await expect(ada).toHaveText('AE');
    await expect(ada).toHaveAttribute('aria-hidden', 'true');
    expect(requested).toContain('/does-not-exist.webp');
    await expect(avatarOf(page, 'carol@example.com').locator('img')).toHaveCount(0);
    await expect(avatarOf(page, 'carol@example.com')).toHaveText('CE');
    expect(requested).not.toContain('/images/avatar-carol.svg');
    await expectAccessible(page, 'home with a broken avatar');

    // Switch to carol: her picture loads over the initials; ada is now another account (initials, no new request).
    requested.length = 0;
    await accounts.filter({ hasText: 'carol@example.com' }).locator('[data-testid="auth-kit-account-switch"]').click();
    await expect(page.locator('body')).toContainText('Logged in as carol@example.com');
    const carol = avatarOf(page, 'carol@example.com');
    await expect(carol).toHaveClass(/\bis-loaded\b/);
    const img = carol.locator('img.nowo-ui-avatar__image');
    await expect(img).toHaveAttribute('alt', '');
    await expect(img).toHaveAttribute('referrerpolicy', 'same-origin');
    await expect(img).toHaveAttribute('width', '32');
    await expect.poll(() => img.evaluate((el: HTMLImageElement) => el.naturalWidth)).toBeGreaterThan(0);
    await expect(avatarOf(page, DEMO_EMAIL).locator('img')).toHaveCount(0);
    await expect(avatarOf(page, DEMO_EMAIL)).toHaveText('AE');
    expect(requested).not.toContain('/does-not-exist.webp');
    expect(await page.locator('[data-testid="demo-accounts"] [onerror], [data-testid="demo-accounts"] [style]').count()).toBe(0);
    await expectAccessible(page, 'home with a picture');

    await page.locator('[data-testid="auth-kit-account-sign-out-all"]').click();
  });
});
