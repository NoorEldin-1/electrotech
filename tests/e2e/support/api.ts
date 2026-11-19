import { randomUUID } from 'node:crypto';
import { expect, type APIRequestContext, type PlaywrightWorkerArgs } from '@playwright/test';
import { PASSWORD, USERS, type RoleKey } from './users';

/**
 * A REST API client (api/v1) signed in as a role, with the same Sanctum token
 * flow the mobile app uses.
 */
export async function apiAs(playwright: PlaywrightWorkerArgs['playwright'], baseURL: string, role: RoleKey): Promise<APIRequestContext> {
  const anonymous = await playwright.request.newContext({
    baseURL,
    extraHTTPHeaders: { Accept: 'application/json' },
  });

  // Every API write needs an Idempotency-Key (RequireIdempotencyKey answers
  // 400 without one), the login included.
  const response = await anonymous.post('/api/v1/auth/login', {
    headers: { 'Idempotency-Key': randomUUID() },
    data: { email: USERS[role].email, password: PASSWORD, device_name: `e2e-${role}` },
  });
  expect(response.status(), `API login as ${role}`).toBe(200);
  const body = await response.json();
  const token: string = body.data.token;
  await anonymous.dispose();

  return playwright.request.newContext({
    baseURL,
    extraHTTPHeaders: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  });
}
