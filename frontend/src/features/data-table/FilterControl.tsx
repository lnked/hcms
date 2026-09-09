import { useQuery } from '@tanstack/react-query'
import { clsx } from 'clsx'
import { DatePickerField } from '@/components/ui/date-picker'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { useI18n } from '@/i18n'
import { entryLabel, fetchRelatedList } from '@/lib/relatedEntries'
import type { SchemaField } from '@/types/field'
import { filterControlKind } from './filters'
import styles from './FilterControl.module.css'

interface FilterControlProps {
  field: SchemaField
  /** Column header, used to build the accessible name. */
  label: string
  value: string
  onChange: (value: string) => void
}

/** Renders the filter input matching the column type: checkbox, select, picker or text. */
export function FilterControl({ field, label, value, onChange }: FilterControlProps) {
  const { t } = useI18n()
  const ariaLabel = t('entries.filterField', { field: label })
  const kind = filterControlKind(field)

  if (kind === 'none') return null

  if (kind === 'boolean') {
    return <BooleanFilter ariaLabel={ariaLabel} value={value} onChange={onChange} />
  }

  if (kind === 'enum') {
    const options = Array.isArray(field.config.options) ? field.config.options.map(String) : []
    return (
      <FilterSelect ariaLabel={ariaLabel} value={value} onChange={onChange}>
        {options.map((option) => (
          <option key={option} value={option}>
            {option}
          </option>
        ))}
      </FilterSelect>
    )
  }

  if (kind === 'relation') {
    return <RelationFilter field={field} ariaLabel={ariaLabel} value={value} onChange={onChange} />
  }

  // Datetime columns are matched by day prefix, so the filter never edits time.
  if (kind === 'date') {
    return (
      <DatePickerField
        value={value}
        onChange={(next) => onChange(next ?? '')}
        format={typeof field.config.format === 'string' ? field.config.format : ''}
        aria-label={ariaLabel}
        className={clsx(styles.control)}
      />
    )
  }

  return (
    <Input
      type={kind === 'number' ? 'number' : 'text'}
      step={kind === 'number' && field.type === 'float' ? 'any' : undefined}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      placeholder={kind === 'text' ? t('entries.filterPlaceholder') : undefined}
      aria-label={ariaLabel}
      className={clsx(styles.control)}
    />
  )
}

/** Tri-state: unset (any), checked (true), unchecked (false). */
function BooleanFilter({
  ariaLabel,
  value,
  onChange,
}: {
  ariaLabel: string
  value: string
  onChange: (value: string) => void
}) {
  const { t } = useI18n()
  const state = value === '1' ? 'on' : value === '0' ? 'off' : 'any'
  const stateLabel =
    state === 'on' ? t('common.yes') : state === 'off' ? t('common.no') : t('entries.filterAny')

  return (
    <label className={clsx(styles.boolLabel)}>
      <input
        type="checkbox"
        className={clsx(styles.boolInput)}
        checked={state === 'on'}
        ref={(el) => {
          if (el) el.indeterminate = state === 'any'
        }}
        aria-label={ariaLabel}
        title={stateLabel}
        onChange={() => onChange(state === 'any' ? '1' : state === 'on' ? '0' : '')}
      />
      <span>{stateLabel}</span>
    </label>
  )
}

function FilterSelect({
  ariaLabel,
  value,
  onChange,
  disabled,
  children,
}: {
  ariaLabel: string
  value: string
  onChange: (value: string) => void
  disabled?: boolean
  children: React.ReactNode
}) {
  const { t } = useI18n()
  return (
    <Select
      value={value}
      disabled={disabled}
      onChange={(e) => onChange(e.target.value)}
      aria-label={ariaLabel}
      className={clsx(styles.control)}
    >
      <option value="">{t('entries.filterAny')}</option>
      {children}
    </Select>
  )
}

function RelationFilter({
  field,
  ariaLabel,
  value,
  onChange,
}: {
  field: SchemaField
  ariaLabel: string
  value: string
  onChange: (value: string) => void
}) {
  const relatedSlug = String(field.config.relatedSlug ?? '')
  const labelField = String(field.config.labelField ?? 'id')
  const options = useQuery({
    queryKey: ['relation-filter-options', relatedSlug],
    queryFn: () => fetchRelatedList(relatedSlug),
    enabled: relatedSlug !== '',
    staleTime: 30_000,
  })

  return (
    <FilterSelect
      ariaLabel={ariaLabel}
      value={value}
      onChange={onChange}
      disabled={options.isLoading}
    >
      {(options.data ?? []).map((row) => (
        <option key={row.id} value={row.id}>
          {entryLabel(row, labelField)}
        </option>
      ))}
    </FilterSelect>
  )
}
