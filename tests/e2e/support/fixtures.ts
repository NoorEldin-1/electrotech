import { test as base, expect, type Browser, type Page } from '@playwright/test';
import { authFile, type RoleKey } from './users';

/**
 * `test` with one extra guarantee: a server error (HTTP 5xx from the app) seen
 * by any page of the test fails it — a Livewire update that answers 500 is a
 * bug even when the UI happens to carry on.
 */
interface Fixtures {
  serverErrors: string[];
  /** A page signed in as the given role, tracked for server errors. */
  pageAs: (role: RoleKey) => Promise<Page>;
}

export function watchServerErrors(page: Page, bucket: string[]): void {
  page.on('response', (response) => {
    if (response.status() < 500) return;
    const origin = new URL(page.url() === 'about:blank' ? response.url() : page.url()).origin;
    if (!response.url().startsWith(origin)) return;
    bucket.push(`${response.status()} ${response.request().method()} ${response.url()}`);
  });
}

export const test = base.extend<Fixtures>({
  serverErrors: [
    async ({ page }, use) => {
      const bucket: string[] = [];
      watchServerErrors(page, bucket);
      await use(bucket);
      expect(bucket, 'the app answered with a server error').toEqual([]);
    },
    { auto: true },
  ],

  pageAs: async ({ browser, baseURL, serverErrors }, use) => {
    const contexts: Awaited<ReturnType<Browser['newContext']>>[] = [];

    await use(async (role) => {
      const context = await browser.newContext({ baseURL, storageState: authFile(role), viewport: { width: 1440, height: 900 } });
      contexts.push(context);
      const page = await context.newPage();
      watchServerErrors(page, serverErrors);
      return page;
    });

    await Promise.all(contexts.map((context) => context.close()));
  },
});

export { expect };
