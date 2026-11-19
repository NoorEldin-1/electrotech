import { apiAs } from '../support/api';
import { readChainSnapshot, seed, type ChainSnapshot } from '../support/artisan';
import { containsAmount, open, statusOf } from '../support/filament';
import { expect, test } from '../support/fixtures';
import { rx } from '../support/lang';

/**
 * Final_Modifications Tasks 6-8: the inventory is split over three accounts
 * (1310 raw materials, 1320 work in progress, 1330 finished goods), every
 * store movement posts its own journal entry, and the valuation page reads the
 * ledger.
 *
 * E2eInventoryChainSeeder drives the real services (purchase, issue, finish,
 * deliver) and snapshots the ledger it ends up with; the checks below are of
 * two kinds:
 *  - the figures that follow from the plan (1310 = 1500, 1320 = 1000, ...),
 *  - the screens against the ledger snapshot ("the numbers match the posted
 *    journals").
 *
 *   purchase 100 @ 50 = 5000
 *   X issue 2000, finished      -> 1330
 *   Y issue 1000, in production -> 1320
 *   Z issue  500, finished, delivered -> cost of goods sold
 */
test.describe.configure({ mode: 'serial' });

let ledger: ChainSnapshot;

test.beforeAll(() => {
  seed('Database\\Seeders\\E2eInventoryChainSeeder');
  ledger = readChainSnapshot();
});

const sum = (snapshot: ChainSnapshot) =>
  snapshot.accounts['1300'].balance + snapshot.accounts['1310'].balance + snapshot.accounts['1320'].balance + snapshot.accounts['1330'].balance;

test.describe('the journals the chain posted', () => {
  test('the three store accounts exist in the chart of accounts', () => {
    for (const code of ['1310', '1320', '1330'] as const) {
      expect(ledger.accounts[code].exists, `account ${code} exists`).toBe(true);
    }
  });

  test('purchase and issues leave 1500 in the raw store and 1000 in work in progress', () => {
    expect(ledger.accounts['1310'].balance).toBeCloseTo(ledger.expected['1310'], 2);
    expect(ledger.accounts['1320'].balance).toBeCloseTo(ledger.expected['1320'], 2);
  });

  test('the finished-but-undelivered cost sits in 1330 and the delivered cost went to cost of goods sold', () => {
    expect(ledger.accounts['1330'].balance, '1330 holds operation X (2000)').toBeCloseTo(2000, 2);
    expect(ledger.accounts['5070'].balance, 'cost of goods sold carries operation Z (500)').toBeCloseTo(500, 2);
  });

  test('nothing is posted to the old parent inventory account any more', () => {
    expect(ledger.accounts['1300'].balance).toBeCloseTo(0, 2);
  });

  test('the books balance', () => {
    expect(ledger.posted_entries, 'automatic journals were posted').toBeGreaterThan(0);
    expect(ledger.total_debit).toBeCloseTo(ledger.total_credit, 2);
    expect(ledger.total_debit).toBeGreaterThan(0);
  });
});

test.describe('finance sees it on screen', () => {
  test('the trial balance lists the three store accounts with their balances and is balanced', async ({ pageAs }) => {
    const page = await pageAs('finance');
    await open(page, '/admin/trial-balance');

    for (const code of ['1310', '1320', '1330'] as const) {
      const row = page.locator('table tbody tr').filter({ hasText: code }).first();
      await expect(row, `trial balance row ${code}`).toBeVisible();
      const text = await row.innerText();
      expect(containsAmount(text, Math.abs(ledger.accounts[code].balance)), `${code} balance in "${text}"`).toBe(true);
    }

    await expect(page.locator('.fi-badge').filter({ hasText: rx('resources.trial_balance.balanced') }).first()).toBeVisible();
  });

  test('the journal entries list shows the automatic entries', async ({ pageAs }) => {
    const page = await pageAs('finance');
    await open(page, '/admin/journal-entries');

    const rows = page.locator('.fi-ta-table tbody tr');
    await expect(rows.first()).toBeVisible();
    expect(await rows.count()).toBeGreaterThanOrEqual(Math.min(ledger.posted_entries, 5));
  });

  test('the valuation page cards carry the ledger balances', async ({ pageAs }) => {
    const page = await pageAs('finance');
    await open(page, '/admin/inventory-valuation');

    const cards: Array<[string, number]> = [
      ['raw_materials', ledger.accounts['1310'].balance],
      ['work_in_progress', ledger.accounts['1320'].balance],
      ['finished_goods', ledger.accounts['1330'].balance],
      ['total', sum(ledger)],
    ];

    for (const [key, amount] of cards) {
      const card = page.locator(`[data-card="${key}"]`);
      await expect(card, `valuation card ${key}`).toBeVisible();
      const text = await card.innerText();
      expect(containsAmount(text, amount), `card ${key} shows ${amount}, got "${text}"`).toBe(true);
    }

    // The reconciliation block and the per-operation work in progress exist.
    await expect(page.locator('[data-total="ledger"]')).toBeVisible();
    await expect(page.locator('body')).toContainText(/E2E-CHAIN-Y|E2E chain operation Y/);
  });

  test('the as-of date filter drops the position to zero before the first movement', async ({ pageAs }) => {
    const page = await pageAs('finance');
    await open(page, '/admin/inventory-valuation');

    await page.locator('#inventory-valuation-as-of').fill('2020-01-01');
    await expect(page.locator('[data-card="total"]')).toContainText(/(^|[^\d.])0(\.00)?(?!\d)/);
    expect(containsAmount(await page.locator('[data-card="total"]').innerText(), sum(ledger))).toBe(false);
  });

  test('the general manager reads the same page', async ({ pageAs }) => {
    const page = await pageAs('generalManager');
    await open(page, '/admin/inventory-valuation');
    expect(containsAmount(await page.locator('[data-card="total"]').innerText(), sum(ledger))).toBe(true);
  });

  test('the valuation API agrees with the ledger', async ({ playwright, baseURL }) => {
    const api = await apiAs(playwright, baseURL!, 'finance');
    const response = await api.get('/api/v1/reports/inventory-valuation');
    expect(response.status()).toBe(200);

    const { data } = await response.json();
    const byKey = Object.fromEntries((data.stores as Array<{ key: string; ledger: string | null }>).map((store) => [store.key, Number(store.ledger)]));

    expect(byKey.raw_materials).toBeCloseTo(ledger.accounts['1310'].balance, 2);
    expect(byKey.work_in_progress).toBeCloseTo(ledger.accounts['1320'].balance, 2);
    expect(byKey.finished_goods).toBeCloseTo(ledger.accounts['1330'].balance, 2);
    expect(Number(data.totals.ledger)).toBeCloseTo(sum(ledger), 2);
    await api.dispose();
  });
});

test.describe('who may read the valuation', () => {
  test('sales, warehouse and factory are refused on the page and in the API', async ({ pageAs, playwright, baseURL }) => {
    for (const role of ['sales', 'warehouse', 'factory'] as const) {
      const page = await pageAs(role);
      expect(await statusOf(page, '/admin/inventory-valuation'), `${role} page`).toBe(403);

      const api = await apiAs(playwright, baseURL!, role);
      expect((await api.get('/api/v1/reports/inventory-valuation')).status(), `${role} API`).toBe(403);
      await api.dispose();
    }
  });
});
