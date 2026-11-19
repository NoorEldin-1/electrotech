import { randomUUID } from 'node:crypto';
import { apiAs } from '../support/api';
import { projectId } from '../support/artisan';
import { field, fillField, notification, open, submitForm } from '../support/filament';
import { expect, test } from '../support/fixtures';
import { rxExact } from '../support/lang';

/**
 * Final_Modifications Task 3: the engineer's contact details (phone, e-mail,
 * office, site) sit next to the engineer's name in their own section of the
 * operation form, are saved with it, and are served by the REST API.
 */
test.describe.configure({ mode: 'serial' });

const CODE = 'E2E-ENGINEER';

test('a sales user fills the engineer section on the operation form and it is saved', async ({ pageAs, playwright, baseURL }) => {
  const page = await pageAs('sales');
  const id = projectId(CODE);

  await open(page, `/admin/projects/${id}/edit`);

  // One section holds all five fields.
  await expect(page.getByText(rxExact('resources.engineer_details.heading')).first()).toBeVisible();

  await fillField(page, rxExact('resources.projects.fields.engineer_name'), 'Eng. Hossam Fathy');
  await fillField(page, rxExact('resources.engineer_details.phone'), '01012345678');
  await fillField(page, rxExact('resources.engineer_details.email'), 'hossam.fathy@e2e.test');
  await fillField(page, rxExact('resources.engineer_details.office'), 'Nile Engineering Office');
  await fillField(page, rxExact('resources.engineer_details.site'), 'New Cairo - Plot 12');

  await submitForm(page);
  await expect(notification(page, /.+/)).toBeVisible();

  // Reloading the form shows what was saved.
  await open(page, `/admin/projects/${id}/edit`);
  await expect(field(page, rxExact('resources.engineer_details.phone')).locator('input').first()).toHaveValue('01012345678');
  await expect(field(page, rxExact('resources.engineer_details.email')).locator('input').first()).toHaveValue('hossam.fathy@e2e.test');
  await expect(field(page, rxExact('resources.engineer_details.office')).locator('input').first()).toHaveValue('Nile Engineering Office');
  await expect(field(page, rxExact('resources.engineer_details.site')).locator('input').first()).toHaveValue('New Cairo - Plot 12');

  // The API serves the same values.
  const api = await apiAs(playwright, baseURL!, 'sales');
  const response = await api.get(`/api/v1/projects/${id}`);
  expect(response.status()).toBe(200);
  const { data } = await response.json();
  expect(data).toMatchObject({
    engineer_name: 'Eng. Hossam Fathy',
    engineer_phone: '01012345678',
    engineer_email: 'hossam.fathy@e2e.test',
    engineer_office: 'Nile Engineering Office',
    engineer_site: 'New Cairo - Plot 12',
  });
  await api.dispose();
});

test('the operation file (read-only view) shows the engineer details', async ({ pageAs }) => {
  const page = await pageAs('generalManager');
  await open(page, `/admin/projects/${projectId(CODE)}`);

  // Either an infolist (text) or a disabled form (input values) renders it.
  const shown = [
    await page.locator('body').innerText(),
    ...(await page.locator('input, textarea').evaluateAll((els) => els.map((el) => (el as HTMLInputElement).value))),
  ].join('\n');
  for (const value of ['Eng. Hossam Fathy', 'hossam.fathy@e2e.test', 'Nile Engineering Office', 'New Cairo - Plot 12', '01012345678']) {
    expect(shown, `the operation file shows ${value}`).toContain(value);
  }
});

test('the API updates the engineer details and refuses a malformed e-mail', async ({ playwright, baseURL }) => {
  const api = await apiAs(playwright, baseURL!, 'sales');
  const id = projectId(CODE);

  const rejected = await api.patch(`/api/v1/projects/${id}`, {
    headers: { 'Idempotency-Key': randomUUID() },
    data: { engineer_email: 'not-an-email' },
  });
  expect(rejected.status()).toBe(422);

  const updated = await api.patch(`/api/v1/projects/${id}`, {
    headers: { 'Idempotency-Key': randomUUID() },
    data: { engineer_phone: '0233334444', engineer_site: 'Alexandria - Smouha' },
  });
  expect(updated.status()).toBe(200);

  const shown = await api.get(`/api/v1/projects/${id}`);
  const { data } = await shown.json();
  expect(data.engineer_phone).toBe('0233334444');
  expect(data.engineer_site).toBe('Alexandria - Smouha');
  // Untouched fields keep their value, and the rejected e-mail did not leak in.
  expect(data.engineer_email).toBe('hossam.fathy@e2e.test');
  expect(data.engineer_office).toBe('Nile Engineering Office');

  await api.dispose();
});
