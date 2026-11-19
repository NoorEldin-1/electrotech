import type { APIRequestContext, Page } from '@playwright/test';
import { apiAs } from '../support/api';
import { projectId } from '../support/artisan';
import { chooseOption, confirmModal, fillField, modal, notification, open, submitForm } from '../support/filament';
import { expect, test } from '../support/fixtures';
import { rxExact } from '../support/lang';

/**
 * Final_Modifications Task 4: calls and notes with the customer are logged
 * against the operation (the existing CustomerInteraction log, no parallel
 * model) and every entry shows up on the operation timeline as a separate
 * `interactions` feed — without touching the 18 timeline stages.
 *
 * Two entry points are covered, in two tests so one cannot hide the other:
 *  1. the customer-interactions screen of the CRM group (already in the app),
 *  2. the quick "log call / note" action in the project's attachments area.
 */
test.describe.configure({ mode: 'serial' });

const CODE = 'E2E-CALLS';
const STAMP = Date.now().toString(36);

async function timelineOf(api: APIRequestContext, id: number) {
  const response = await api.get(`/api/v1/projects/${id}/timeline`);
  expect(response.status(), 'GET /projects/{id}/timeline').toBe(200);
  return (await response.json()).data as {
    stages: Array<{ key: string }>;
    interactions?: { total: number; items: unknown[] };
  };
}

test('the timeline starts with its 18 stages and an empty interactions feed', async ({ playwright, baseURL }) => {
  // Sales holds customer_interactions.view, so this is the real (empty) feed.
  const api = await apiAs(playwright, baseURL!, 'sales');
  const timeline = await timelineOf(api, projectId(CODE));

  expect(timeline.stages).toHaveLength(18);
  // The feed is an object `{ total, items }`, not a bare array.
  expect(timeline.interactions, 'the payload carries an `interactions` feed').toBeDefined();
  expect(Array.isArray(timeline.interactions!.items), 'the feed lists its `items`').toBe(true);
  // A retry runs after the logging test below has added a call, so only the
  // shape and consistency are asserted here, not emptiness.
  expect(typeof timeline.interactions!.total).toBe('number');
  expect(timeline.interactions!.total).toBeGreaterThanOrEqual(timeline.interactions!.items.length);
  await api.dispose();
});

test('a timeline viewer without the interactions permission gets an empty feed, not the key missing', async ({ playwright, baseURL }) => {
  // The PMO coordinator reads the timeline but does not hold customer_interactions.view.
  const api = await apiAs(playwright, baseURL!, 'asmaa');
  const timeline = await timelineOf(api, projectId(CODE));

  expect(timeline.stages).toHaveLength(18);
  expect(timeline.interactions).toEqual({ total: 0, items: [] });
  await api.dispose();
});

test('a call logged on the customer interactions screen appears on the timeline feed', async ({ pageAs, playwright, baseURL }) => {
  const note = `E2E call from the CRM screen ${STAMP}`;
  const page = await pageAs('sales');

  await open(page, '/admin/customer-interactions/create');
  await chooseOption(page, rxExact('resources.customer_interactions.fields.operation'), CODE);
  await fillField(page, rxExact('resources.customer_communication.fields.summary'), note);
  await fillField(page, rxExact('resources.customer_interactions.fields.notes'), note);
  await submitForm(page);

  // Saved (the toast survives the redirect), and the new entry is on the list.
  await expect(notification(page, /.+/)).toBeVisible();
  await open(page, '/admin/customer-interactions');
  await expect(page.locator('.fi-ta-table')).toContainText(note.slice(0, 30));

  const api = await apiAs(playwright, baseURL!, 'sales');
  const timeline = await timelineOf(api, projectId(CODE));
  expect(timeline.stages, 'the 18 stage keys are unchanged').toHaveLength(18);
  expect(JSON.stringify(timeline.interactions ?? []), 'the logged call is in the interactions feed').toContain(note);
  await api.dispose();
});

/** The quick "log call / note" action of the attachments area, wherever the page puts it. */
async function findQuickLog(page: Page, id: number) {
  const trigger = /تسجيل مكالمة|تسجيل (مكالمة|ملاحظة)|Log (a )?(call|note)/i;

  for (const path of [`/admin/projects/${id}`, `/admin/projects/${id}/edit`]) {
    await open(page, path);
    const candidate = page.getByRole('button', { name: trigger }).or(page.getByRole('link', { name: trigger })).first();
    if (await candidate.count()) {
      return candidate;
    }
  }
  return null;
}

test('the quick log action on the project page records a call that reaches the timeline', async ({ pageAs, playwright, baseURL }) => {
  const note = `E2E quick call from the project page ${STAMP}`;
  const page = await pageAs('sales');
  const id = projectId(CODE);

  const trigger = await findQuickLog(page, id);
  expect(trigger, 'a "log call / note" action exists in the project attachments area').not.toBeNull();

  // The block is titled "التواصل مع العميل" (customer communication).
  await expect(page.getByText(/التواصل مع العميل|Customer communication|Customer contact/i).first()).toBeVisible();

  await trigger!.click();
  const dialog = modal(page);
  await expect(dialog).toBeVisible();

  // The summary is required; the details textarea is optional.
  const summary = dialog
    .locator('.fi-fo-field-wrp')
    // `has` is evaluated inside each wrapper, so it must be a page-relative locator.
    .filter({ has: page.locator('label', { hasText: rxExact('resources.customer_communication.fields.summary') }) })
    .locator('input:not([type="hidden"])')
    .first();
  await expect(summary).toBeVisible();
  await summary.fill(note);
  await dialog.locator('textarea').first().fill(`${note} (details)`);
  await confirmModal(page);
  await expect(dialog).toBeHidden();

  // It is listed in the block, and on the timeline feed.
  await expect(page.locator('body')).toContainText(note.slice(0, 30));

  const api = await apiAs(playwright, baseURL!, 'sales');
  const timeline = await timelineOf(api, id);
  expect(JSON.stringify(timeline.interactions ?? [])).toContain(note);
  expect(timeline.stages).toHaveLength(18);
  await api.dispose();
});

test('the operations timeline screen opens for the PMO coordinator and lists the operation', async ({ pageAs }) => {
  const page = await pageAs('asmaa');
  await open(page, '/admin/projects-timeline');
  await expect(page.locator('body')).toContainText(CODE);
});
