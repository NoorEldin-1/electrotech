import { expect, type Locator, type Page, type Response } from '@playwright/test';
import { PASSWORD, type TestUser } from './users';

/** Sign in through the real Filament login form. */
export async function login(page: Page, user: Pick<TestUser, 'email'>, password: string = PASSWORD): Promise<void> {
  await page.goto('/admin/login');
  await page.waitForLoadState('networkidle');
  await page.locator('input[type="email"]').fill(user.email);
  await page.locator('input[type="password"]').fill(password);
  await page.locator('form button[type="submit"]').first().click();
}

/**
 * Open a panel URL and fail on anything that is not a healthy page: an HTTP
 * error status, Laravel's debug page, or Livewire's error modal.
 */
export async function open(page: Page, url: string): Promise<Response | null> {
  const response = await gotoSurvivingRestart(page, url);
  expect(response, `no response for ${url}`).not.toBeNull();
  expect(response!.status(), `${url} answered HTTP ${response!.status()}`).toBeLessThan(400);
  await page.waitForLoadState('networkidle');
  await expectHealthyPage(page, url);
  return response;
}

/**
 * page.goto that waits out a restart of the application server. The CI server
 * (support/serve.sh) is restarted within a second if php -S ever dies; a goto
 * that lands in that gap fails with a connection error, and trying again is
 * what a user would do. HTTP errors are not retried: those are real answers.
 */
async function gotoSurvivingRestart(page: Page, url: string): Promise<Response | null> {
  const attempts = 4;
  for (let attempt = 1; ; attempt++) {
    try {
      return await page.goto(url, { waitUntil: 'domcontentloaded' });
    } catch (error) {
      const connectionError = /ERR_(CONNECTION_REFUSED|CONNECTION_RESET|EMPTY_RESPONSE)/.test((error as Error).message);
      if (!connectionError || attempt >= attempts) {
        throw error;
      }
      await page.waitForTimeout(1_500);
    }
  }
}

export async function expectHealthyPage(page: Page, label = page.url()): Promise<void> {
  const body = (await page.locator('body').innerText()).slice(0, 4000);
  expect(body, `${label} rendered an error page`).not.toMatch(/Whoops|Server Error|ErrorException|QueryException|Undefined (variable|array key|property)|Call to (undefined|a member)/);
  await expect(page.locator('#livewire-error'), `${label} raised a Livewire error`).toHaveCount(0);
}

/** Status of a URL for a signed-in page, without rendering it. */
export async function statusOf(page: Page, url: string): Promise<number> {
  const response = await page.request.get(url, { maxRedirects: 0, failOnStatusCode: false });
  return response.status();
}

/** Filament form field wrapper (label + control) found by its label text. */
export function field(page: Page, label: RegExp): Locator {
  return page.locator('.fi-fo-field-wrp').filter({ has: page.locator('label', { hasText: label }) }).last();
}

/** Fill a plain text input / textarea by its label. */
export async function fillField(page: Page, label: RegExp, value: string): Promise<void> {
  const control = field(page, label).locator('input:not([type="hidden"]), textarea').first();
  await expect(control, `field ${label}`).toBeVisible();
  await control.fill(value);
}

/** Pick an option in a Filament select (searchable or not) by its text. */
export async function chooseOption(page: Page, label: RegExp, option: string | RegExp): Promise<void> {
  const wrapper = field(page, label);
  const native = wrapper.locator('select');

  if (await native.count()) {
    const target = await native.locator('option').filter({ hasText: option }).first().getAttribute('value');
    await native.selectOption(target ?? '');
    return;
  }

  await wrapper.locator('button[aria-haspopup="listbox"], .fi-fo-select button').first().click();

  const search = wrapper.locator('input[type="search"]');
  if (await search.count()) {
    const typed = typeof option === 'string' ? option : option.source.replace(/\\/g, '');
    await search.first().fill(typed.slice(0, 40));
  }

  await wrapper.locator('[role="option"]').filter({ hasText: option }).first().click();
}

/** Submit the page's main form (create / edit / action modal). */
export async function submitForm(page: Page): Promise<void> {
  await page
    .locator('form[wire\\:submit="save"] button[type="submit"], form[wire\\:submit="create"] button[type="submit"]')
    .first()
    .click();
}

/**
 * A Filament toast whose text matches. Only rendered (visible) toasts count:
 * the closed database-notifications panel in the topbar holds hidden
 * `.fi-no-notification` cards too, and `.first()` would otherwise pick one of
 * those and wait on it forever.
 */
export function notification(page: Page, text: RegExp): Locator {
  return page.locator('.fi-no-notification:visible').filter({ hasText: text }).first();
}

/** The open modal window. */
export function modal(page: Page): Locator {
  return page.locator('.fi-modal-window:visible').last();
}

/** Confirm the open modal (the submit button of its footer). */
export async function confirmModal(page: Page): Promise<void> {
  const dialog = modal(page);
  await expect(dialog).toBeVisible();
  await dialog.locator('button[type="submit"]').first().click();
}

/** A table row containing the text, after narrowing the table with its search box. */
export async function tableRow(page: Page, text: string): Promise<Locator> {
  const search = page.locator('.fi-ta-search-field input').first();
  if (await search.count()) {
    await search.fill(text);
  }
  const row = page.locator('.fi-ta-table tbody tr').filter({ hasText: text }).first();
  await expect(row, `table row "${text}"`).toBeVisible();
  return row;
}

/** Open the row's ⋮ actions menu and return its panel. */
export async function openRowActions(row: Locator): Promise<Locator> {
  await row.locator('.fi-ta-actions .fi-dropdown-trigger button, .fi-ta-actions button').first().click();
  const panel = row.locator('.fi-dropdown-panel');
  await expect(panel.first()).toBeVisible();
  return panel.first();
}

/** One menu entry (button or link) of an open actions panel, by its label. */
export function menuItem(panel: Locator, label: RegExp): Locator {
  return panel.locator('button, a').filter({ hasText: label });
}

/** Digits of a page text in a comparable form: Arabic-Indic digits to ASCII, thousands separators dropped. */
export function normalizeNumbers(text: string): string {
  return text
    .replace(/[\u0660-\u0669]/g, (d) => String(d.charCodeAt(0) - 0x0660))
    .replace(/[\u06F0-\u06F9]/g, (d) => String(d.charCodeAt(0) - 0x06f0))
    .replace(/\u066B/g, '.')
    .replace(/(?<=\d)[,\u066C\u00A0\u202F](?=\d{3}\b)/g, '');
}

/** Whether `amount` (e.g. 1500) appears as a number in the text, with or without decimals. */
export function containsAmount(text: string, amount: number): boolean {
  const plain = normalizeNumbers(text);
  const whole = Number.isInteger(amount) ? String(amount) : amount.toFixed(2);
  const pattern = new RegExp(`(^|[^\\d.])${whole.replace('.', '\\.')}(\\.0+)?(?![\\d])`);
  return pattern.test(plain);
}
