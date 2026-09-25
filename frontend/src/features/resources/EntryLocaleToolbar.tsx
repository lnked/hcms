import { Badge } from '@/components/ui/badge'
import { Select } from '@/components/ui/select'
import { useI18n } from '@/i18n'
import styles from './EntryLocaleToolbar.module.css'

export interface EntryLocaleOption {
  code: string
  label: string
}

export interface EntryTranslationSibling {
  id: number
  locale: string
}

interface EntryLocaleToolbarProps {
  currentLocale: string
  locales: EntryLocaleOption[]
  siblings: EntryTranslationSibling[]
  missingLocales: EntryLocaleOption[]
  loading?: boolean
  adding?: boolean
  onSwitch: (entryId: number) => void
  onAdd: (locale: string) => void
}

export function EntryLocaleToolbar({
  currentLocale,
  locales,
  siblings,
  missingLocales,
  loading = false,
  adding = false,
  onSwitch,
  onAdd,
}: EntryLocaleToolbarProps) {
  const { t } = useI18n()
  const currentLabel = locales.find((l) => l.code === currentLocale)?.label ?? currentLocale

  return (
    <div className={styles.root}>
      <Badge variant="outline" className={styles.badge} title={t('entries.locale')}>
        {currentLabel}
        {currentLocale !== '' ? ` (${currentLocale})` : null}
      </Badge>
      <Select
        aria-label={t('entries.switchLocale')}
        containerClassName={styles.switch}
        value={currentLocale}
        disabled={loading || siblings.length === 0}
        onChange={(e) => {
          const next = siblings.find((s) => s.locale === e.target.value)
          if (next) onSwitch(next.id)
        }}
      >
        {siblings.map((s) => {
          const label = locales.find((l) => l.code === s.locale)?.label ?? s.locale
          return (
            <option key={s.id} value={s.locale}>
              {label} ({s.locale})
            </option>
          )
        })}
      </Select>
      {missingLocales.length > 0 ? (
        <Select
          aria-label={t('entries.addTranslation')}
          containerClassName={styles.add}
          value=""
          disabled={adding}
          onChange={(e) => {
            const code = e.target.value
            if (code) onAdd(code)
          }}
        >
          <option value="">{t('entries.addTranslationShort')}</option>
          {missingLocales.map((l) => (
            <option key={l.code} value={l.code}>
              {l.label} ({l.code})
            </option>
          ))}
        </Select>
      ) : null}
    </div>
  )
}
