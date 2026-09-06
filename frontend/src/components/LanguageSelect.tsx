import { ChevronDown } from 'lucide-react'
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
    <div className={cn('relative', className)}>
      <select
        id={id}
        className={cn(
          'flex h-9 w-full cursor-pointer appearance-none rounded-md border border-input bg-background px-3 pr-8 text-sm shadow-sm transition-colors',
          'hover:border-muted-foreground/40',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
          'disabled:cursor-not-allowed disabled:opacity-50',
        )}
        value={value}
        onChange={(e) => onChange(e.target.value as Locale)}
        aria-label={t('common.language')}
      >
        {LOCALES.map((locale) => (
          <option key={locale} value={locale}>
            {t(localeKeys[locale])}
          </option>
        ))}
      </select>
      <ChevronDown
        className="pointer-events-none absolute right-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground"
        aria-hidden
      />
    </div>
  )
}
