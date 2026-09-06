import type { MessageKey } from './en'
import { en } from './en'
import { ru } from './ru'

export const LOCALES = ['en', 'ru'] as const
export type Locale = (typeof LOCALES)[number]

export const LOCALE_STORAGE_KEY = 'hcms_locale'

const catalogs: Record<Locale, Record<MessageKey, string>> = {
  en,
  ru,
}

export function isLocale(value: unknown): value is Locale {
  return typeof value === 'string' && (LOCALES as readonly string[]).includes(value)
}

export function normalizeLocale(value: unknown, fallback: Locale = 'en'): Locale {
  return isLocale(value) ? value : fallback
}

export function readStoredLocale(): Locale | null {
  try {
    const raw = localStorage.getItem(LOCALE_STORAGE_KEY)
    return isLocale(raw) ? raw : null
  } catch {
    return null
  }
}

export function writeStoredLocale(locale: Locale): void {
  try {
    localStorage.setItem(LOCALE_STORAGE_KEY, locale)
  } catch {
    // ignore quota / private mode
  }
}

export function translate(
  locale: Locale,
  key: MessageKey,
  params?: Record<string, string | number>,
): string {
  const template = catalogs[locale][key] ?? catalogs.en[key] ?? key
  if (!params) {
    return template
  }
  return template.replace(/\{(\w+)\}/g, (_, name: string) =>
    params[name] !== undefined ? String(params[name]) : `{${name}}`,
  )
}
