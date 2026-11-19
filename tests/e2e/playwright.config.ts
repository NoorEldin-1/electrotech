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

  // Skipped when something already listens on the port (local re-runs).
  webServer: process.env.E2E_BASE_URL
    ? undefined
    : {
        command: `php artisan serve --host=127.0.0.1 --port=${port}`,
        cwd: repoRoot,
        url: `${baseURL}/admin/login`,
        timeout: 120_000,
        reuseExistingServer: !process.env.CI,
        env: {
          // Several workers so a slow Livewire request never stalls the asset
          // requests that come with it. sqlite waits for its lock (PDO's
          // default timeout), and the specs themselves run one at a time.
          PHP_CLI_SERVER_WORKERS: '4',
        },
        stdout: 'ignore',
        stderr: 'pipe',
      },
});
