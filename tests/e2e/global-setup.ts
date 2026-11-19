import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { chromium, type FullConfig } from '@playwright/test';
import { login } from './support/filament';
import { LANG_FILE } from './support/lang';
import { ALL_ROLE_KEYS, AUTH_DIR, USERS, authFile } from './support/users';

const repoRoot = path.resolve(__dirname, '..', '..');

/**
 * 1. Dump the translations (the specs find controls by their lang strings).
 * 2. Sign every role in once through the real login form and keep the session
 *    cookies, so a spec starts already authenticated. The smoke spec still
 *    exercises the login form itself.
 */
export default async function globalSetup(config: FullConfig): Promise<void> {
  fs.mkdirSync(path.dirname(LANG_FILE), { recursive: true });
  const json = execFileSync('php', [path.join(__dirname, 'support', 'dump-lang.php')], {
    cwd: repoRoot,
    encoding: 'utf8',
    maxBuffer: 64 * 1024 * 1024,
  });
  fs.writeFileSync(LANG_FILE, json);

  fs.mkdirSync(AUTH_DIR, { recursive: true });
  const baseURL = config.projects[0].use.baseURL as string;
  const browser = await chromium.launch();

  try {
    for (const key of ALL_ROLE_KEYS) {
      const context = await browser.newContext({ baseURL });
      const page = await context.newPage();

      await login(page, USERS[key]);
      await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 45_000 });

      await context.storageState({ path: authFile(key) });
      await context.close();
    }
  } finally {
    await browser.close();
  }
}
