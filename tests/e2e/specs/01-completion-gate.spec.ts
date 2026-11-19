import { apiAs } from '../support/api';
import { projectId, seed } from '../support/artisan';
import { confirmModal, menuItem, notification, open, openRowActions, tableRow } from '../support/filament';
import { expect, test } from '../support/fixtures';
import { rx } from '../support/lang';

/**
 * Final_Modifications Task 2 / ruling R4: an operation can be completed only
 * once every manufacturing order is finished, has a passed quality sheet, and
 * everything ordered went out on activated delivery vouchers — and the sales
 * roles never get the button at all.
 *
 * Seeded by E2eSeeder:
 *   E2E-GATE-BLOCKED  order still running, no sheet, no delivery
 *   E2E-GATE-READY    finished + approved sheet + activated delivery
 *   E2E-GATE-STEP     finished + approved sheet + DRAFT delivery (activated below)
 *
 * The specs run in order and share the database on purpose: READY and STEP are
 * completed for real, so they must be checked by the sales roles first.
 */
test.describe.configure({ mode: 'serial' });

const COMPLETE = () => rx('resources.operations.actions.complete');

async function statusOfOperation(playwright: Parameters<typeof apiAs>[0], baseURL: string, code: string): Promise<string> {
  const api = await apiAs(playwright, baseURL, 'admin');
  const response = await api.get(`/api/v1/projects/${projectId(code)}`);
  expect(response.status()).toBe(200);
  const body = await response.json();
  await api.dispose();
  return body.data.status.value as string;
}

/** The "Complete" entry of an operation's ⋮ menu on the active operations list. */
async function completeEntry(page: import('@playwright/test').Page, code: string) {
  await open(page, '/admin/active-projects');
  const row = await tableRow(page, code);
  const panel = await openRowActions(row);
  return menuItem(panel, COMPLETE());
}

/** The tooltip Filament puts on an action (it is an `x-tooltip` attribute on the entry). */
async function tooltipOf(entry: import('@playwright/test').Locator): Promise<string> {
  return entry.evaluate((el) => (el.closest('[x-tooltip]') ?? el).getAttribute('x-tooltip') ?? el.outerHTML);
}

test('an operation with unfinished work cannot be completed: the action is disabled and says why', async ({ pageAs, playwright, baseURL }) => {
  const page = await pageAs('generalManager');
  const entry = await completeEntry(page, 'E2E-GATE-BLOCKED');

  await expect(entry).toBeVisible();
  await expect(entry).toBeDisabled();
  // The tooltip names the manufacturing order that is still open.
  expect(await tooltipOf(entry)).toContain('WO-E2E-GATE-BLOCKED');

  expect(await statusOfOperation(playwright, baseURL!, 'E2E-GATE-BLOCKED')).toBe('in_progress');
});

test('the sales roles never see the Complete action, even on an operation that is ready', async ({ pageAs }) => {
  for (const role of ['sales', 'salesManager', 'salesOfficer'] as const) {
    const page = await pageAs(role);
    await open(page, '/admin/active-projects');

    const row = await tableRow(page, 'E2E-GATE-READY');
    const panel = await openRowActions(row);

    // The menu itself is there (View), the Complete entry is not.
    await expect(panel.locator('button, a').first()).toBeVisible();
    await expect(menuItem(panel, COMPLETE()), `${role} must not see Complete`).toHaveCount(0);
  }
});

test('a finished, inspected and delivered operation is completed by the general manager', async ({ pageAs, playwright, baseURL }) => {
  const page = await pageAs('generalManager');
  const entry = await completeEntry(page, 'E2E-GATE-READY');

  await expect(entry).toBeEnabled();
  await entry.click();
  await confirmModal(page);

  await expect(notification(page, rx('resources.operations.notifications.completed'))).toBeVisible();
  expect(await statusOfOperation(playwright, baseURL!, 'E2E-GATE-READY')).toBe('completed');
});

test('blocked until the last delivery is activated, then the factory manager can complete it', async ({ pageAs, playwright, baseURL }) => {
  const page = await pageAs('factory');

  // Still blocked: manufactured and inspected, but only a draft delivery voucher.
  const blocked = await completeEntry(page, 'E2E-GATE-STEP');
  await expect(blocked).toBeDisabled();

  // The missing piece happens (the delivery voucher gets activated) ...
  seed('Database\\Seeders\\E2eActivateDeliverySeeder');

  // ... and the very same screen now offers the action.
  const unblocked = await completeEntry(page, 'E2E-GATE-STEP');
  await expect(unblocked).toBeEnabled();
  await unblocked.click();
  await confirmModal(page);

  await expect(notification(page, rx('resources.operations.notifications.completed'))).toBeVisible();
  expect(await statusOfOperation(playwright, baseURL!, 'E2E-GATE-STEP')).toBe('completed');
});
