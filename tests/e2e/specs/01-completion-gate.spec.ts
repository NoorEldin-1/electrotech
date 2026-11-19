import { apiAs } from '../support/api';
import { projectId, seed } from '../support/artisan';
import { confirmModal, menuItem, modal, notification, open, openRowActions, tableRow } from '../support/filament';
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

test('an operation with unfinished work cannot be completed: the modal says why and confirming is refused', async ({ pageAs, playwright, baseURL }) => {
  const page = await pageAs('generalManager');
  const entry = await completeEntry(page, 'E2E-GATE-BLOCKED');

  // The action is offered (a disabled entry inside the dropdown could not
  // explain itself); its confirmation modal lists what is missing.
  await expect(entry).toBeVisible();
  await expect(entry).toBeEnabled();
  await entry.click();

  const dialog = modal(page);
  await expect(dialog).toBeVisible();
  await expect(dialog).toContainText('WO-E2E-GATE-BLOCKED');
  await expect(dialog).toContainText(rx('resources.operations.completion_blocked_tooltip'));

  // Confirming anyway is refused: a danger notification, status unchanged.
  await confirmModal(page);
  await expect(notification(page, rx('resources.common.action_failed'))).toBeVisible();

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

  // Still blocked: manufactured and inspected, but only a draft delivery
  // voucher. The modal names the missing delivery, and confirming is refused.
  const blocked = await completeEntry(page, 'E2E-GATE-STEP');
  await expect(blocked).toBeEnabled();
  await blocked.click();
  await expect(modal(page)).toContainText(rx('errors.operations.blockers.no_active_delivery'));
  await confirmModal(page);
  await expect(notification(page, rx('resources.common.action_failed'))).toBeVisible();
  expect(await statusOfOperation(playwright, baseURL!, 'E2E-GATE-STEP')).toBe('in_progress');

  // The missing piece happens (the delivery voucher gets activated) ...
  seed('Database\\Seeders\\E2eActivateDeliverySeeder');

  // ... and the very same screen now confirms without listing any blocker.
  const unblocked = await completeEntry(page, 'E2E-GATE-STEP');
  await expect(unblocked).toBeEnabled();
  await unblocked.click();
  await expect(modal(page)).not.toContainText(rx('errors.operations.blockers.no_active_delivery'));
  await confirmModal(page);

  await expect(notification(page, rx('resources.operations.notifications.completed'))).toBeVisible();
  expect(await statusOfOperation(playwright, baseURL!, 'E2E-GATE-STEP')).toBe('completed');
});
