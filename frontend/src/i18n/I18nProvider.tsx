import {
  createContext,
  createElement,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import {
  isLocale,
  normalizeLocale,
  readStoredLocale,
  translate,
  writeStoredLocale,
  type Locale,
} from './locale'
import type { MessageKey } from './en'

type TranslateFn = (key: MessageKey, params?: Record<string, string | number>) => string

interface I18nContextValue {
  locale: Locale
  setLocale: (locale: Locale) => void
  t: TranslateFn
}

const I18nContext = createContext<I18nContextValue | null>(null)

function applyDocumentLang(locale: Locale) {
  document.documentElement.lang = locale
}

export function I18nProvider({
  children,
  initialLocale,
}: {
  children: ReactNode
  initialLocale?: Locale
}) {
  const [locale, setLocaleState] = useState<Locale>(() => {
    if (initialLocale) return initialLocale
    return readStoredLocale() ?? 'en'
  })

  useEffect(() => {
    applyDocumentLang(locale)
    writeStoredLocale(locale)
  }, [locale])

  const setLocale = useCallback((next: Locale) => {
    if (!isLocale(next)) return
    setLocaleState(next)
  }, [])

  const t = useCallback<TranslateFn>((key, params) => translate(locale, key, params), [locale])

  const value = useMemo(() => ({ locale, setLocale, t }), [locale, setLocale, t])

  return createElement(I18nContext.Provider, { value }, children)
}

export function useI18n(): I18nContextValue {
  const ctx = useContext(I18nContext)
  if (!ctx) {
    throw new Error('useI18n must be used within I18nProvider')
  }
  return ctx
}

export function LocaleBootstrap({ children }: { children: ReactNode }) {
  const { setLocale } = useI18n()

  useEffect(() => {
    let cancelled = false
    void fetch('/admin/api/settings/locale', { headers: { Accept: 'application/json' } })
      .then(async (response) => {
        if (!response.ok || cancelled) return
        const payload = (await response.json()) as {
          data?: { language?: string }
          language?: string
        }
        const language = payload.data?.language ?? payload.language
        if (!cancelled && isLocale(language)) {
          const stored = readStoredLocale()
          // Prefer server setting when local storage is empty / first visit
          if (!stored) {
            setLocale(normalizeLocale(language))
          }
        }
      })
      .catch(() => {
        // CMS may be uninstalled — ignore
      })
    return () => {
      cancelled = true
    }
  }, [setLocale])

  return children
}
