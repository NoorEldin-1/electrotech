import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

export const repoRoot = path.resolve(__dirname, '..', '..', '..');

/** Run a seeder class against the database the app under test uses. */
export function seed(seederClass: string): string {
  return execFileSync('php', ['artisan', 'db:seed', `--class=${seederClass}`, '--force'], {
    cwd: repoRoot,
    encoding: 'utf8',
    maxBuffer: 16 * 1024 * 1024,
    timeout: 240_000,
  });
}

export interface ChainSnapshot {
  accounts: Record<'1300' | '1310' | '1320' | '1330' | '5070', { exists: boolean; balance: number; lines: number }>;
  total_debit: number;
  total_credit: number;
  posted_entries: number;
  operations: string[];
  expected: { '1310': number; '1320': number };
}

/** What E2eInventoryChainSeeder recorded about the ledger. */
export function readChainSnapshot(): ChainSnapshot {
  const file = path.join(repoRoot, 'storage', 'app', 'e2e', 'chain.json');
  return JSON.parse(fs.readFileSync(file, 'utf8')) as ChainSnapshot;
}

/** Database id of a seeded operation, by its code (E2eSeeder writes the map). */
export function projectId(code: string): number {
  const file = path.join(repoRoot, 'storage', 'app', 'e2e', 'projects.json');
  const map = JSON.parse(fs.readFileSync(file, 'utf8')) as Record<string, number>;
  const id = map[code];
  if (id === undefined) {
    throw new Error(`E2E: operation ${code} is not in storage/app/e2e/projects.json — did E2eSeeder run?`);
  }
  return id;
}
