/*
 * Operator Console i18n.
 *
 * Three responsibilities:
 *
 *   1. Load a flat translation dictionary keyed by dotted path (e.g.
 *      `tabs.work_orders` → "Work Orders"). The dictionary lives in
 *      ./locales/{en,ar}.js — separate modules so the Service Worker
 *      caches them as ordinary shell assets and the operator can
 *      switch language while offline.
 *
 *   2. Provide `t(key, params)` for runtime lookups, with simple
 *      `{placeholder}` interpolation. Unknown keys return the key
 *      itself so missing translations are visible in development
 *      rather than producing eerie empty strings.
 *
 *   3. Manage `<html lang>` and `<html dir>` so the browser applies
 *      RTL automatically when the locale is Arabic. The active locale
 *      is persisted in IndexedDB meta so it survives reloads (the SW
 *      shell does not run JS until the user reopens the page, so we
 *      cannot keep it in module memory).
 *
 * Locale selection precedence on bootstrap:
 *   a. Explicit value saved in IndexedDB meta (`locale`).
 *   b. `navigator.language` starts with `ar` → Arabic.
 *   c. English.
 *
 * The module emits a `i18n:change` window event on every successful
 * setLocale call so views can re-render without polling.
 */

import { metaGet, metaSet } from './db.js';

const SUPPORTED = ['en', 'ar'];

let dict = {};
let currentLocale = 'en';

/**
 * Look up a dotted key path in the current dictionary. Returns the key
 * verbatim on miss so a forgotten translation is obvious during QA
 * rather than producing a blank cell.
 */
export function t(key, params = {}) {
    const parts = String(key).split('.');
    let cur = dict;
    for (const p of parts) {
        if (cur && typeof cur === 'object' && p in cur) {
            cur = cur[p];
        } else {
            return key;
        }
    }
    if (typeof cur !== 'string') return key;
    return cur.replace(/\{(\w+)\}/g, (_, k) => (k in params ? String(params[k]) : ''));
}

export function getLocale() {
    return currentLocale;
}

export function isRtl() {
    return currentLocale === 'ar';
}

/**
 * Switch the active locale. Persists the choice, swaps `<html lang>`
 * and `<html dir>`, retranslates every element marked with the
 * `data-i18n` attribute, and emits `i18n:change` so view modules can
 * re-render.
 */
export async function setLocale(locale) {
    if (!SUPPORTED.includes(locale)) locale = 'en';

    const mod = await import(`./locales/${locale}.js`);
    dict = mod.default || {};
    currentLocale = locale;

    const html = document.documentElement;
    html.lang = locale;
    html.dir = locale === 'ar' ? 'rtl' : 'ltr';

    applyDomTranslations();

    try {
        await metaSet('locale', locale);
    } catch {
        // Persistence is best-effort. The current page session is
        // already correctly localized; the next reload will fall back
        // to navigator.language until the user picks again.
    }

    window.dispatchEvent(new CustomEvent('i18n:change', { detail: { locale } }));
}

/**
 * Walk every element with `data-i18n="key.path"` and replace its
 * textContent with the translated value. For attributes (placeholder,
 * title, aria-label) use `data-i18n-attr-{name}="key.path"`.
 *
 * Why textContent (not innerHTML): the source strings are author-
 * controlled but the DOM should still be defended against accidental
 * HTML injection if a translator slips an `<` into a string.
 */
export function applyDomTranslations(root = document) {
    for (const node of root.querySelectorAll('[data-i18n]')) {
        const key = node.getAttribute('data-i18n');
        node.textContent = t(key);
    }
    for (const node of root.querySelectorAll('*')) {
        for (const attr of node.attributes) {
            if (attr.name.startsWith('data-i18n-attr-')) {
                const target = attr.name.replace('data-i18n-attr-', '');
                node.setAttribute(target, t(attr.value));
            }
        }
    }
}

/**
 * Bootstrap on app start. Order matters: we read the saved choice
 * first, then fall back to the browser's preferred language, then
 * English. This way a user who explicitly switched to English on an
 * Arabic-locale tablet keeps English next reload.
 */
export async function bootstrapLocale() {
    let initial = null;
    try {
        initial = await metaGet('locale');
    } catch {
        // IndexedDB unavailable (private mode, quota, etc.) — fall
        // through to language detection.
    }

    if (!initial || !SUPPORTED.includes(initial)) {
        const nav = (navigator.language || 'en').toLowerCase();
        initial = nav.startsWith('ar') ? 'ar' : 'en';
    }

    await setLocale(initial);
}
