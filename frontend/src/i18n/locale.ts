import { ar } from './ar'
import { en } from './en'
import { ru } from './ru'
import type { MessageKey } from './en'

export type TextDirection = 'ltr' | 'rtl'

export const LOCALES = ['en', 'ru', 'ar'] as const
export type Locale = (typeof LOCALES)[number]

const LOCALE_META: Record<Locale, { code: Locale; dir: TextDirection }> = {
  en: { code: 'en', dir: 'ltr' },
  ru: { code: 'ru', dir: 'ltr' },
  ar: { code: 'ar', dir: 'rtl' },
}

const LOCALE_STORAGE_KEY = 'hcms_locale'

const catalogs: Record<Locale, Partial<Record<MessageKey, string>>> = {
  en,
  ru,
  ar,
}

export function localeDir(locale: Locale): TextDirection {
  return LOCALE_META[locale].dir
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
