import { Select } from '@/components/ui/select'
import { useI18n } from '@/i18n'
import { LOCALES, type Locale } from '@/i18n'

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
    <Select
      id={id}
      containerClassName={className}
      value={value}
      onChange={(e) => onChange(e.target.value as Locale)}
      aria-label={t('common.language')}
    >
      {LOCALES.map((locale) => (
        <option key={locale} value={locale}>
          {t(localeKeys[locale])}
        </option>
      ))}
    </Select>
  )
}
