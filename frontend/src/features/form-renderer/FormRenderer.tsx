import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { MediaFieldPicker } from '@/features/media/MediaFieldPicker'
import { RichTextEditor } from '@/features/form-renderer/RichTextEditor'
import { useI18n } from '@/i18n'
import { entryLabel, fetchRelatedList } from '@/lib/relatedEntries'
import { slugifyUrl } from '@/lib/slugify'
import type { SchemaField } from '@/types/field'

export type EntryValues = Record<string, unknown>

interface FormRendererProps {
  fields: SchemaField[]
  values: EntryValues
  onChange: (values: EntryValues) => void
  disabled?: boolean
  entryId?: number | null
}

function relationCardinality(field: SchemaField): 'manyToOne' | 'oneToMany' {
  return field.config.cardinality === 'oneToMany' ? 'oneToMany' : 'manyToOne'
}

function isOneToManyRelation(field: SchemaField): boolean {
  return field.type === 'relation' && relationCardinality(field) === 'oneToMany'
}

function shouldRenderField(field: SchemaField): boolean {
  if (field.hidden) return false
  if (isOneToManyRelation(field)) return true
  return field.writable && !field.readonly
}

function applySlugUpdates(
  fields: SchemaField[],
  values: EntryValues,
  changedName: string,
  changedValue: unknown,
  touchedSlugs: Set<string>,
): EntryValues {
  const next: EntryValues = { ...values, [changedName]: changedValue }
  for (const field of fields) {
    if (field.type !== 'slug') continue
    if (touchedSlugs.has(field.name)) continue
    const source = String(field.config.associatedWith ?? '')
    if (!source || source !== changedName) continue
    const maxLength = Number(field.config.maxLength ?? 255) || 255
    const generated = slugifyUrl(changedValue == null ? '' : String(changedValue), maxLength)
    next[field.name] = generated === '' ? null : generated
  }
  return next
}

export function FormRenderer({ fields, values, onChange, disabled, entryId }: FormRendererProps) {
  const { t } = useI18n()
  const [touchedSlugs, setTouchedSlugs] = useState<Set<string>>(() => new Set())
  const visible = fields
    .filter(shouldRenderField)
    .slice()
    .sort((a, b) => a.sortOrder - b.sortOrder)

  function set(name: string, value: unknown) {
    let nextTouched = touchedSlugs
    if (fields.some((field) => field.type === 'slug' && field.name === name)) {
      nextTouched = new Set(touchedSlugs)
      nextTouched.add(name)
      setTouchedSlugs(nextTouched)
    }
    onChange(applySlugUpdates(fields, values, name, value, nextTouched))
  }

  const resolvedEntryId =
    entryId ?? (typeof values.id === 'number' ? values.id : Number(values.id) || null)

  return (
    <div className="space-y-4">
      {visible.map((field) => {
        const id = `field-${field.name}`
        const value = values[field.name]
        return (
          <div key={field.name} className="space-y-1.5">
            <Label htmlFor={id}>
              {field.label || field.name}
              {field.required && !isOneToManyRelation(field) ? (
                <span className="text-destructive"> *</span>
              ) : null}
            </Label>
            {field.description ? (
              <p className="text-xs text-muted-foreground">{field.description}</p>
            ) : null}
            {field.type === 'relation' ? (
              <RelationControl
                field={field}
                id={id}
                value={value}
                disabled={disabled}
                entryId={resolvedEntryId}
                onChange={(next) => set(field.name, next)}
              />
            ) : (
              renderControl(field, id, value, disabled, set)
            )}
          </div>
        )
      })}
      {visible.length === 0 ? (
        <p className="text-sm text-muted-foreground">{t('entries.noWritable')}</p>
      ) : null}
    </div>
  )
}

function RelationControl({
  field,
  id,
  value,
  disabled,
  entryId,
  onChange,
}: {
  field: SchemaField
  id: string
  value: unknown
  disabled?: boolean
  entryId?: number | null
  onChange: (value: unknown) => void
}) {
  const { t } = useI18n()
  const cardinality = relationCardinality(field)
  const relatedSlug = String(field.config.relatedSlug ?? '')
  const labelField = String(field.config.labelField ?? 'id')
  const foreignKey = String(field.config.foreignKey ?? '')
  const canLoad = Boolean(relatedSlug) && !(cardinality === 'oneToMany' && !entryId)
  const filterQs =
    cardinality === 'oneToMany' && foreignKey && entryId
      ? `filter[${encodeURIComponent(foreignKey)}]=${encodeURIComponent(String(entryId))}`
      : ''

  const relatedQuery = useQuery({
    queryKey: ['relation-options', relatedSlug, cardinality, foreignKey, entryId, filterQs],
    queryFn: () => fetchRelatedList(relatedSlug, filterQs),
    enabled: canLoad,
  })

  const options = relatedQuery.data ?? []
  const loading = relatedQuery.isLoading || relatedQuery.isFetching
  const error =
    relatedQuery.error instanceof Error
      ? relatedQuery.error.message
      : relatedQuery.error
        ? t('common.requestFailed')
        : null

  if (!relatedSlug) {
    return (
      <p className="text-sm text-muted-foreground">{t('schema.relation.relatedSlugRequired')}</p>
    )
  }

  if (cardinality === 'oneToMany') {
    if (!entryId) {
      return <p className="text-sm text-muted-foreground">{t('entries.relationSaveFirst')}</p>
    }
    if (loading) {
      return <p className="text-sm text-muted-foreground">{t('common.loading')}</p>
    }
    if (error) {
      return <p className="text-sm text-destructive">{error}</p>
    }
    if (options.length === 0) {
      return <p className="text-sm text-muted-foreground">{t('entries.relationEmpty')}</p>
    }
    return (
      <ul className="space-y-1 rounded-md border p-3 text-sm">
        {options.map((row) => (
          <li key={row.id} className="font-mono text-xs">
            #{row.id} · {entryLabel(row, labelField)}
          </li>
        ))}
      </ul>
    )
  }

  return (
    <div className="space-y-1">
      <Select
        id={id}
        disabled={disabled || loading}
        value={value == null ? '' : String(value)}
        onChange={(e) =>
          onChange(e.target.value === '' ? null : Number.parseInt(e.target.value, 10))
        }
      >
        <option value="">—</option>
        {options.map((row) => (
          <option key={row.id} value={row.id}>
            {entryLabel(row, labelField)}
          </option>
        ))}
      </Select>
      {loading ? <p className="text-xs text-muted-foreground">{t('common.loading')}</p> : null}
      {error ? <p className="text-xs text-destructive">{error}</p> : null}
    </div>
  )
}

function renderControl(
  field: SchemaField,
  id: string,
  value: unknown,
  disabled: boolean | undefined,
  set: (name: string, value: unknown) => void,
) {
  if (field.type === 'boolean') {
    return (
      <label className="flex items-center gap-2 text-sm">
        <input
          id={id}
          type="checkbox"
          className="h-4 w-4"
          checked={Boolean(value)}
          disabled={disabled}
          onChange={(e) => set(field.name, e.target.checked)}
        />
        {value ? 'Yes' : 'No'}
      </label>
    )
  }

  if (field.type === 'image' || field.type === 'file') {
    const formats = Array.isArray(field.config.formats) ? (field.config.formats as string[]) : []
    const sizes = Array.isArray(field.config.sizes)
      ? (field.config.sizes as import('@/types/field').ImageSizeConfig[])
      : []
    const accept =
      formats.length > 0
        ? formats.map((ext) => `.${String(ext).replace(/^\./, '')}`).join(',')
        : field.type === 'image'
          ? 'image/*'
          : undefined
    return (
      <MediaFieldPicker
        id={id}
        value={value}
        disabled={disabled}
        accept={accept}
        multiple={Boolean(field.config.multiple)}
        formats={formats}
        sizes={field.type === 'image' ? sizes : []}
        isImage={field.type === 'image'}
        onChange={(next) => set(field.name, next)}
      />
    )
  }

  if (field.type === 'richtext') {
    return (
      <RichTextEditor
        id={id}
        disabled={disabled}
        value={typeof value === 'string' ? value : value == null ? '' : String(value)}
        onChange={(next) => set(field.name, next)}
      />
    )
  }

  if (field.type === 'text' || field.type === 'json') {
    return (
      <Textarea
        id={id}
        className="min-h-24"
        disabled={disabled}
        value={typeof value === 'string' ? value : value == null ? '' : JSON.stringify(value)}
        onChange={(e) => set(field.name, e.target.value)}
      />
    )
  }

  if (field.type === 'enum') {
    const options = Array.isArray(field.config.options) ? field.config.options.map(String) : []
    return (
      <Select
        id={id}
        disabled={disabled}
        value={value == null ? '' : String(value)}
        onChange={(e) => set(field.name, e.target.value)}
      >
        <option value="">—</option>
        {options.map((opt) => (
          <option key={opt} value={opt}>
            {opt}
          </option>
        ))}
      </Select>
    )
  }

  const inputType =
    field.type === 'integer' || field.type === 'float'
      ? 'number'
      : field.type === 'date'
        ? 'date'
        : field.type === 'datetime'
          ? 'datetime-local'
          : field.type === 'email'
            ? 'email'
            : field.type === 'url'
              ? 'url'
              : 'text'

  return (
    <Input
      id={id}
      type={inputType}
      step={field.type === 'float' ? 'any' : undefined}
      disabled={disabled}
      value={value == null ? '' : String(value)}
      onChange={(e) => {
        const raw = e.target.value
        if (field.type === 'integer') {
          set(field.name, raw === '' ? null : Number.parseInt(raw, 10))
          return
        }
        if (field.type === 'float') {
          set(field.name, raw === '' ? null : Number.parseFloat(raw))
          return
        }
        set(field.name, raw)
      }}
    />
  )
}

export function emptyValues(fields: SchemaField[]): EntryValues {
  const values: EntryValues = {}
  for (const field of fields) {
    if (!field.writable || field.hidden || field.readonly) continue
    if (isOneToManyRelation(field)) continue
    if (field.default !== undefined && field.default !== null) {
      values[field.name] = field.default
    } else if (field.type === 'boolean') {
      values[field.name] = false
    } else {
      values[field.name] = null
    }
  }
  return values
}
