import type { APIRequestContext, PlaywrightWorkerArgs } from '@playwright/test';
import { apiAs } from '../support/api';
import { projectId } from '../support/artisan';
import { open } from '../support/filament';
import { expect, test } from '../support/fixtures';
import { rxExact } from '../support/lang';
import type { RoleKey } from '../support/users';

/**
 * Final_Modifications Task 5 / ruling R3: the preliminary drawing, the
 * executive drawing and the site measurements are visible only with
 * `attachments.view_restricted_drawings` — held by the PMO timeline
 * coordinator (أسماء) and the admin, NOT by sales or the general manager.
 * Everyone else must not see them listed, counted or downloaded, and must not
 * be offered them in the upload form.
 *
 * E2E-DRAWINGS carries the three restricted files and one BOQ (unrestricted).
 */
const CODE = 'E2E-DRAWINGS';
const RESTRICTED = ['e2e-preliminary-drawing.pdf', 'e2e-executive-drawing.pdf', 'e2e-site-measurement.pdf'];
const OPEN = 'e2e-boq.pdf';

const ALLOWED: RoleKey[] = ['asmaa', 'admin'];
// Everybody who can open the operation but does not hold the permission.
const DENIED: RoleKey[] = ['sales', 'salesManager', 'salesOfficer', 'generalManager', 'technical', 'factory'];

type Listed = { id: number; file_name: string };

async function listFiles(api: APIRequestContext, id: number): Promise<{ status: number; files: Listed[] }> {
  const response = await api.get('/api/v1/attachments', { params: { owner_type: 'project', owner_id: id } });
  if (response.status() !== 200) {
    return { status: response.status(), files: [] };
  }
  return { status: 200, files: (await response.json()).data as Listed[] };
}

async function filesFor(playwright: PlaywrightWorkerArgs['playwright'], baseURL: string, role: RoleKey, id: number) {
  const api = await apiAs(playwright, baseURL, role);
  const listed = await listFiles(api, id);
  return { api, ...listed };
}

test.describe.configure({ mode: 'serial' });

let restrictedIds: Record<string, number> = {};
let openId = 0;

test('the admin sees every file of the operation', async ({ playwright, baseURL }) => {
  const { api, status, files } = await filesFor(playwright, baseURL!, 'admin', projectId(CODE));
  expect(status).toBe(200);

  const names = files.map((file) => file.file_name);
  for (const name of [...RESTRICTED, OPEN]) {
    expect(names, `admin lists ${name}`).toContain(name);
  }

  restrictedIds = Object.fromEntries(files.filter((file) => RESTRICTED.includes(file.file_name)).map((file) => [file.file_name, file.id]));
  openId = files.find((file) => file.file_name === OPEN)!.id;
  await api.dispose();
});

for (const role of ALLOWED) {
  test(`${role} lists and downloads the restricted drawings`, async ({ playwright, baseURL }) => {
    const { api, status, files } = await filesFor(playwright, baseURL!, role, projectId(CODE));
    expect(status).toBe(200);

    const names = files.map((file) => file.file_name);
    for (const name of [...RESTRICTED, OPEN]) {
      expect(names, `${role} lists ${name}`).toContain(name);
    }

    for (const [name, id] of Object.entries(restrictedIds)) {
      const download = await api.get(`/api/v1/attachments/${id}/download`);
      expect(download.status(), `${role} downloads ${name}`).toBe(200);
    }
    await api.dispose();
  });
}

for (const role of DENIED) {
  test(`${role} neither sees nor downloads the restricted drawings`, async ({ playwright, baseURL }) => {
    const { api, status, files } = await filesFor(playwright, baseURL!, role, projectId(CODE));

    // Opening the operation is allowed for these roles; the drawings are what is hidden.
    expect(status, `${role} can read the operation's attachment list`).toBe(200);

    const names = files.map((file) => file.file_name);
    for (const name of RESTRICTED) {
      expect(names, `${role} must not list ${name}`).not.toContain(name);
    }
    expect(names, 'the unrestricted BOQ is still listed').toContain(OPEN);

    for (const [name, id] of Object.entries(restrictedIds)) {
      const download = await api.get(`/api/v1/attachments/${id}/download`);
      expect(download.status(), `${role} must not download ${name}`).toBe(403);
    }

    const allowed = await api.get(`/api/v1/attachments/${openId}/download`);
    expect(allowed.status(), `${role} still downloads the BOQ`).toBe(200);
    await api.dispose();
  });
}

test('the panel download route streams a restricted drawing to the PMO coordinator and refuses sales', async ({ pageAs }) => {
  expect(Object.keys(restrictedIds), 'the admin test above collected the restricted ids').toHaveLength(RESTRICTED.length);

  const asmaa = await pageAs('asmaa');
  for (const [name, id] of Object.entries(restrictedIds)) {
    const response = await asmaa.request.get(`/attachments/${id}/download`, { failOnStatusCode: false });
    expect(response.status(), `asmaa downloads ${name} through the panel`).toBe(200);
    expect(response.headers()['content-disposition'] ?? '', `${name} is sent as an attachment`).toContain('attachment');
    expect(response.headers()['x-content-type-options']).toBe('nosniff');
  }

  const sales = await pageAs('sales');
  for (const [name, id] of Object.entries(restrictedIds)) {
    const response = await sales.request.get(`/attachments/${id}/download`, { failOnStatusCode: false });
    expect(response.status(), `sales must not download ${name} through the panel`).toBe(403);
  }
});

test('the restricted files have no public /storage URL, while an ordinary one still has', async ({ request }) => {
  // The seeder keeps all four under attachments/e2e/. The three drawings sit on
  // the private disk, so the web server has nothing to serve for them.
  for (const name of RESTRICTED) {
    const response = await request.get(`/storage/attachments/e2e/${name}`, { failOnStatusCode: false, maxRedirects: 0 });
    expect(response.status(), `GET /storage/attachments/e2e/${name} must be a 404`).toBe(404);
  }

  // The same URL shape does work for the unrestricted BOQ, so the 404 above is
  // the move to the private disk and not a mistyped path.
  const boq = await request.get(`/storage/attachments/e2e/${OPEN}`, { failOnStatusCode: false });
  expect(boq.status(), 'the ordinary attachment is still served from /storage').toBe(200);
});

test('the category filter cannot be used to reach a restricted category', async ({ playwright, baseURL }) => {
  const api = await apiAs(playwright, baseURL!, 'sales');
  const response = await api.get('/api/v1/attachments', {
    params: { owner_type: 'project', owner_id: projectId(CODE), category: 'upload' },
  });

  expect([200, 403]).toContain(response.status());
  if (response.status() === 200) {
    expect((await response.json()).data).toHaveLength(0);
  }
  await api.dispose();
});

test('the operation file shows the drawings to the PMO coordinator only', async ({ pageAs }) => {
  const id = projectId(CODE);

  const asmaa = await pageAs('asmaa');
  await open(asmaa, `/admin/projects/${id}`);
  await expect(asmaa.locator('body')).toContainText(RESTRICTED[0]);

  for (const role of ['sales', 'generalManager'] as const) {
    const page = await pageAs(role);
    await open(page, `/admin/projects/${id}`);
    const text = await page.locator('body').innerText();
    for (const name of RESTRICTED) {
      expect(text, `${role} must not see ${name} on the operation file`).not.toContain(name);
    }
  }
});

test('the upload form offers the restricted categories only to those who may see them', async ({ pageAs }) => {
  const id = projectId(CODE);
  const labels = ['resources.enums.attachment_category.upload', 'resources.enums.attachment_category.executive_drawing', 'resources.enums.attachment_category.site_measurement'];

  const labelOf = (page: import('@playwright/test').Page, key: string) =>
    page.locator('.fi-fo-field-wrp label, .fi-fo-field-wrp-label').filter({ hasText: rxExact(key) });

  const admin = await pageAs('admin');
  await open(admin, `/admin/projects/${id}/edit`);
  for (const key of labels) {
    await expect(labelOf(admin, key).first(), `admin is offered ${key}`).toBeAttached();
  }

  const sales = await pageAs('sales');
  await open(sales, `/admin/projects/${id}/edit`);
  for (const key of labels) {
    await expect(labelOf(sales, key), `sales must not be offered ${key}`).toHaveCount(0);
  }
  // ... while an ordinary category is still there.
  await expect(labelOf(sales, 'resources.enums.attachment_category.boq').first()).toBeAttached();
});
