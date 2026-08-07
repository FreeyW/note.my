import zhCN from "../i18n/zh-CN.json";
import en from "../i18n/en.json";

/**
 * Both locales are inlined at build time, so rendering costs no network
 * request — the CSP forbids connecting anywhere but our own origin, and a
 * fetch on the read page would be one more request correlating a reader with
 * a note.
 *
 * The homepage has a real URL per locale (/ and /zh), because hreflang
 * annotates alternate *pages* and cannot describe one URL that swaps language
 * client-side. So on the homepage the server has already decided, and this
 * module simply follows <html lang>. Only the reading page — which is
 * noindex and has no SEO to preserve — falls back to the browser's preference.
 *
 * Nothing is persisted. A cookie or localStorage entry would be the only
 * client-side trace this service leaves, for the sake of a preference the URL
 * or the browser already states.
 */
const CATALOGS: Record<string, Record<string, string>> = {
  "zh-CN": zhCN as Record<string, string>,
  en: en as Record<string, string>,
};

export const LOCALES = Object.keys(CATALOGS);

let current = detect();

function detect(): string {
  // <html lang> first: on the homepage it is authoritative, set by the server
  // from the URL the visitor actually requested.
  const candidates = [document.documentElement.lang, ...(navigator.languages ?? [navigator.language])];
  for (const raw of candidates) {
    if (!raw) continue;
    const tag = raw.toLowerCase();
    if (tag.startsWith("zh")) return "zh-CN";
    if (tag.startsWith("en")) return "en";
  }
  return "en";
}

export function locale(): string {
  return current;
}

export function setLocale(next: string): void {
  if (CATALOGS[next]) {
    current = next;
    document.documentElement.lang = next;
  }
}

/** Look up a string, substituting {name} placeholders. */
export function t(key: string, vars?: Record<string, string | number>): string {
  const text = CATALOGS[current]?.[key] ?? CATALOGS.en[key] ?? key;
  if (!vars) return text;
  return text.replace(/\{(\w+)\}/g, (whole, name) => (name in vars ? String(vars[name]) : whole));
}
