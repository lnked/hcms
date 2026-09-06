import { useI18n } from '@/i18n'
import { LOCALES, type Locale } from '@/i18n'
import { cn } from '@/lib/utils'

interface LanguageSelectProps {
  value: Locale
  onChange: (locale: Locale) => void
  className?: string
  id?: string
}

const localeKeys = {
  en: 'locale.en',
  ru: 'locale.ru',
} as const

export function LanguageSelect({ value, onChange, className, id }: LanguageSelectProps) {
  const { t } = useI18n()

  return (
    <select
      id={id}
      className={cn(
        'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm',
        className,
      )}
      value={value}
      onChange={(e) => onChange(e.target.value as Locale)}
    >
      {LOCALES.map((locale) => (
        <option key={locale} value={locale}>
          {t(localeKeys[locale])}
        </option>
      ))}
    </select>
  )
}
