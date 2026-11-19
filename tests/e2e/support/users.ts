import path from 'node:path';

/**
 * The accounts the suite signs in with. They come from the real
 * DemoUserSeeder (admin ... warehouse) and from E2eSeeder (the rest); every one
 * has the password below. Keep in sync with those two seeders.
 */
export const PASSWORD = 'password';

export type RoleKey =
  | 'admin'
  | 'sales'
  | 'salesManager'
  | 'salesOfficer'
  | 'technical'
  | 'procurement'
  | 'factory'
  | 'warehouse'
  | 'finance'
  | 'generalManager'
  | 'asmaa'
  | 'quality'
  | 'ledger';

export interface TestUser {
  key: RoleKey;
  email: string;
  role: string;
}

export const USERS: Record<RoleKey, TestUser> = {
  admin: { key: 'admin', email: 'admin@electrotech.com', role: 'Admin' },
  sales: { key: 'sales', email: 'sales@electrotech.com', role: 'Sales' },
  salesManager: { key: 'salesManager', email: 'sales.manager@electrotech.com', role: 'Sales_Manager' },
  salesOfficer: { key: 'salesOfficer', email: 'sales.officer@e2e.test', role: 'Sales_Officer' },
  technical: { key: 'technical', email: 'technical@electrotech.com', role: 'Technical_Office' },
  procurement: { key: 'procurement', email: 'procurement@electrotech.com', role: 'Procurement' },
  factory: { key: 'factory', email: 'factory@electrotech.com', role: 'Factory_Manager' },
  warehouse: { key: 'warehouse', email: 'warehouse@electrotech.com', role: 'Warehouse_Manager' },
  finance: { key: 'finance', email: 'finance@e2e.test', role: 'Finance' },
  generalManager: { key: 'generalManager', email: 'general.manager@e2e.test', role: 'General_Manager' },
  asmaa: { key: 'asmaa', email: 'asmaa@e2e.test', role: 'Timeline_Coordinator' },
  quality: { key: 'quality', email: 'quality@e2e.test', role: 'Quality_Inspector' },
  ledger: { key: 'ledger', email: 'ledger@e2e.test', role: 'Ledger_Viewer' },
};

export const ALL_ROLE_KEYS = Object.keys(USERS) as RoleKey[];

export const AUTH_DIR = path.resolve(__dirname, '..', '.auth');

export function authFile(key: RoleKey): string {
  return path.join(AUTH_DIR, `${key}.json`);
}
