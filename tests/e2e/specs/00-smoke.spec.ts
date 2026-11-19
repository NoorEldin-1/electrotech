import { expect, test } from '../support/fixtures';
import { login, open, statusOf } from '../support/filament';
import { rxAny } from '../support/lang';
import { ALL_ROLE_KEYS, PASSWORD, USERS, authFile, type RoleKey } from '../support/users';

/**
 * Smoke: everybody can sign in, the dashboard renders, and the main screens of
 * every role open without a server error (and the ones a role must not reach
 * answer 403). This is the spec to read first when the suite goes red: if it
 * fails, the feature specs are not telling you anything yet.
 */

test.describe('anonymous visitor', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('the login page renders and a wrong password is refused', async ({ page }) => {
    await open(page, '/admin/login');
    await expect(page.locator('input[type="email"]')).toBeVisible();
    await expect(page.locator('input[type="password"]')).toBeVisible();

    await login(page, USERS.admin, `${PASSWORD}-wrong`);

    await expect(page.locator('.fi-fo-field-wrp-error-message').first()).toBeVisible();
    expect(page.url()).toContain('/login');
  });

  test('is sent to the login page from a protected screen', async ({ page }) => {
    await page.goto('/admin/projects');
    await expect(page).toHaveURL(/\/admin\/login/);
  });

  test('can read the public documentation and the API meta endpoint', async ({ page, request }) => {
    const documentation = await page.goto('/documentation');
    expect(documentation!.status()).toBe(200);

    const meta = await request.get('/api/v1/meta', { headers: { Accept: 'application/json' } });
    expect(meta.status()).toBe(200);
  });

  for (const key of ALL_ROLE_KEYS) {
    test(`${USERS[key].role} (${key}) signs in through the form and reaches the dashboard`, async ({ page }) => {
      await login(page, USERS[key]);
      await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 45_000 });

      await open(page, '/admin');
      await expect(page.locator('.fi-sidebar, .fi-topbar').first()).toBeVisible();
      await expect(page.locator('.fi-sidebar-nav a[href]').first()).toBeAttached();
    });
  }
});

test.describe('admin', () => {
  test.use({ storageState: authFile('admin') });

  test('can open every screen in the sidebar', async ({ page }) => {
    test.setTimeout(420_000);

    await open(page, '/admin');
    const origin = new URL(page.url()).origin;
    const hrefs = await page
      .locator('.fi-sidebar-nav a[href]')
      .evaluateAll((links) => links.map((link) => (link as HTMLAnchorElement).href));

    const targets = [...new Set(hrefs)]
      .filter((href) => href.startsWith(`${origin}/admin`))
      .filter((href) => !/\/admin\/(locale|logout)/.test(href));

    // A real menu of screens, not an empty one.
    expect(targets.length, 'sidebar links found').toBeGreaterThan(15);

    const failures: string[] = [];
    for (const href of targets) {
      try {
        await open(page, href);
      } catch (error) {
        failures.push(`${href} -> ${(error as Error).message.split('\n')[0]}`);
      }
    }
    expect(failures, 'sidebar screens that failed to open').toEqual([]);
  });

  test('sees the navigation groups in the app language', async ({ page }) => {
    await open(page, '/admin');
    await expect(page.locator('.fi-sidebar')).toContainText(
      rxAny('navigation.groups.sales_crm', 'navigation.groups.finance', 'navigation.groups.warehouse'),
    );
  });
});

/**
 * Per role: screens that must open, and screens that must stay closed. The
 * lists mirror RoleAndPermissionSeeder; a mismatch is either a permission
 * regression or a seeder change this list has to follow.
 */
const MATRIX: Array<{ role: RoleKey; allow: string[]; deny: string[] }> = [
  { role: 'sales', allow: ['/admin/projects', '/admin/customers', '/admin/active-projects', '/admin/customer-interactions'], deny: ['/admin/journal-entries', '/admin/users', '/admin/inventory-valuation'] },
  { role: 'salesManager', allow: ['/admin/projects', '/admin/active-projects', '/admin/operations-overview'], deny: ['/admin/journal-entries', '/admin/users'] },
  { role: 'salesOfficer', allow: ['/admin/projects', '/admin/customers', '/admin/customer-interactions'], deny: ['/admin/journal-entries', '/admin/users'] },
  { role: 'technical', allow: ['/admin/boms', '/admin/work-orders', '/admin/items', '/admin/projects-timeline'], deny: ['/admin/journal-entries', '/admin/users'] },
  { role: 'procurement', allow: ['/admin/purchase-orders', '/admin/suppliers', '/admin/addition-vouchers'], deny: ['/admin/journal-entries', '/admin/users'] },
  { role: 'factory', allow: ['/admin/work-orders', '/admin/quality-sheets', '/admin/issue-vouchers', '/admin/active-projects'], deny: ['/admin/journal-entries', '/admin/users'] },
  { role: 'warehouse', allow: ['/admin/addition-vouchers', '/admin/issue-vouchers', '/admin/inventory-transactions'], deny: ['/admin/journal-entries', '/admin/users', '/admin/inventory-valuation'] },
  { role: 'finance', allow: ['/admin/accounts', '/admin/journal-entries', '/admin/trial-balance', '/admin/journal-daybook', '/admin/balance-sheet', '/admin/sales-invoices', '/admin/inventory-valuation'], deny: ['/admin/users'] },
  { role: 'generalManager', allow: ['/admin/operations-overview', '/admin/trial-balance', '/admin/active-projects', '/admin/delivery-minutes', '/admin/inventory-valuation'], deny: ['/admin/users'] },
  { role: 'asmaa', allow: ['/admin/projects-timeline', '/admin/active-projects'], deny: ['/admin/journal-entries', '/admin/customers', '/admin/users'] },
  { role: 'quality', allow: ['/admin/quality-sheets'], deny: ['/admin/journal-entries', '/admin/projects', '/admin/users'] },
  { role: 'ledger', allow: ['/admin/accounts', '/admin/trial-balance', '/admin/journal-daybook', '/admin/general-ledger-report'], deny: ['/admin/projects', '/admin/users'] },
];

for (const { role, allow, deny } of MATRIX) {
  test.describe(`${USERS[role].role} access`, () => {
    test.use({ storageState: authFile(role) });

    test('the main screens open', async ({ page }) => {
      test.setTimeout(240_000);
      await open(page, '/admin');
      for (const url of allow) {
        await open(page, url);
      }
    });

    test('the screens outside the role stay closed', async ({ page }) => {
      for (const url of deny) {
        const status = await statusOf(page, url);
        expect([403, 404], `${role} -> ${url}`).toContain(status);
      }
    });
  });
}
