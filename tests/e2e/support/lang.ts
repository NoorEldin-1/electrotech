import fs from 'node:fs';
import path from 'node:path';

/**
 * Translations as the app ships them (lang/ar and lang/en), dumped to JSON by
 * global-setup. The specs ask for a key and get a RegExp that matches the text
 * in EITHER locale, so they do not care which one the app runs in.
 */
export const LANG_FILE = path.resolve(__dirname, '..', '.cache', 'lang.json');

type Dump = { ar: Record<string, string>; en: Record<string, string> };

let cache: Dump | undefined;

function load(): Dump {
  if (!cache) {
    cache = JSON.parse(fs.readFileSync(LANG_FILE, 'utf8')) as Dump;
  }
  return cache;
}

function escapeRegExp(text: string): string {
  return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** The text of a key in both locales, with `:placeholders` cut off. */
export function texts(key: string): string[] {
  const dump = load();
  const found = new Set<string>();

  for (const locale of ['ar', 'en'] as const) {
    const raw = dump[locale][key];
    if (typeof raw !== 'string') continue;

    // "Reasons: :reasons" -> "Reasons"; a sentence starting with a placeholder
    // has nothing stable left to match, so it is dropped.
    const stable = raw.split(/:[A-Za-z_]+/)[0].trim();
    if (stable.length >= 2) found.add(stable);
  }

  return [...found];
}

export function hasKey(key: string): boolean {
  return texts(key).length > 0;
}

/** Case-insensitive RegExp matching the key's text in Arabic or English. */
export function rx(key: string): RegExp {
  const found = texts(key);
  if (found.length === 0) {
    throw new Error(`E2E: translation key "${key}" does not exist in lang/ar or lang/en`);
  }
  return new RegExp(found.map(escapeRegExp).join('|'), 'i');
}

/** The key's text if the key exists, otherwise the fallback pattern. */
export function rxOr(key: string, fallback: RegExp): RegExp {
  return hasKey(key) ? rx(key) : fallback;
}

/** Like rx(), but the whole text of an element must be the label (an optional required-marker star aside). */
export function rxExact(key: string): RegExp {
  const found = texts(key);
  if (found.length === 0) {
    throw new Error(`E2E: translation key "${key}" does not exist in lang/ar or lang/en`);
  }
  return new RegExp(`^\\s*(?:${found.map(escapeRegExp).join('|')})\\s*\\*?\\s*$`, 'i');
}

/** Union of several keys. */
export function rxAny(...keys: string[]): RegExp {
  const found = keys.flatMap((key) => texts(key));
  if (found.length === 0) {
    throw new Error(`E2E: none of the translation keys exist: ${keys.join(', ')}`);
  }
  return new RegExp(found.map(escapeRegExp).join('|'), 'i');
}
