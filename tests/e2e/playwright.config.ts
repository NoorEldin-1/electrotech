import path from 'node:path';
import { defineConfig, devices } from '@playwright/test';

/**
 * Browser E2E suite. It is meant to run in GitHub Actions only (see the `e2e`
 * job in .github/workflows/tests.yml): the job builds the assets, migrates and
 * seeds a sqlite file database, then this config boots `php artisan serve`.
 *
 * Specs run serially on purpose. They share one database, and a few of them
 * change it on purpose (completing an operation, running the inventory chain).
 */
const repoRoot = path.resolve(__dirname, '..', '..');
const port = process.env.E2E_PORT ?? '8000';
const baseURL = process.env.E2E_BASE_URL ?? `http://127.0.0.1:${port}`;

export default defineConfig({
  testDir: './specs',
  outputDir: './test-results',
  globalSetup: './global-setup.ts',

  timeout: 90_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,

  reporter: process.env.CI
    ? [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }], ['github']]
    : [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],

  use: {
    baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
    actionTimeout: 15_000,
    navigationTimeout: 45_000,
    viewport: { width: 1440, height: 900 },
  },

  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } } }],

  // Skipped when E2E_BASE_URL is set: CI starts the server itself in a step of
  // its own (before Playwright), so it is not a child of this process. Locally
  // it is also skipped when something already listens on the port.
  //
  // support/serve.sh runs `php -S` directly and restarts it if it ever dies;
  // `php artisan serve` is avoided on purpose (see the header of that script).
  webServer: process.env.E2E_BASE_URL
    ? undefined
    : {
        command: `bash tests/e2e/support/serve.sh ${port}`,
        cwd: repoRoot,
        url: `${baseURL}/admin/login`,
        timeout: 120_000,
        reuseExistingServer: !process.env.CI,
        stdout: 'ignore',
        stderr: 'pipe',
      },
});
