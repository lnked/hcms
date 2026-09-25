import { describe, expect, it } from 'vitest'
import { LOCALES, isLocale, localeDir, normalizeLocale, translate } from './locale'

describe('locale', () => {
  it('lists en, ru, ar', () => {
    expect([...LOCALES]).toEqual(['en', 'ru', 'ar'])
  })

  it('sets rtl only for ar', () => {
    expect(localeDir('en')).toBe('ltr')
    expect(localeDir('ru')).toBe('ltr')
    expect(localeDir('ar')).toBe('rtl')
  })

  it('falls back missing ar keys to english', () => {
    expect(translate('ar', 'common.saving')).toBe(translate('en', 'common.saving'))
    expect(translate('ar', 'locale.ar')).toBe('العربية')
  })

  it('normalizes unknown to en', () => {
    expect(normalizeLocale('xx')).toBe('en')
    expect(isLocale('ar')).toBe(true)
    expect(isLocale('he')).toBe(false)
  })
})
