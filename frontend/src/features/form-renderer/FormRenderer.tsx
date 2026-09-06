import { useEffect, useState } from 'react'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { MediaFieldPicker } from '@/features/media/MediaFieldPicker'
import { useI18n } from '@/i18n'
import { api, apiPage, getToken } from '@/lib/api'
import type { SchemaField } from '@/types/field'
import type { Resource } from '@/types/resource'
import { cn } from '@/lib/utils'

export type EntryValues = Record<string, unknown>

interface FormRendererProps {
  fields: SchemaField[]
  values: EntryValues
  onChange: (values: EntryValues) => void
  disabled?: boolean
  entryId?: number | null
}

const controlClass =
  'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring'

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

export function FormRenderer({ fields, values, onChange, disabled, entryId }: FormRendererProps) {
  const { t } = useI18n()
  const visible = fields
    .filter(shouldRenderField)
    .slice()
    .sort((a, b) => a.sortOrder - b.sortOrder)

  function set(name: string, value: unknown) {
    onChange({ ...values, [name]: value })
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

type RelatedRow = Record<string, unknown> & { id: number }

async function fetchRelatedList(relatedSlug: string, extraQuery = ''): Promise<RelatedRow[]> {
  const headers = new Headers({ Accept: 'application/json' })
  const token = getToken()
  if (token) headers.set('Authorization', `Bearer ${token}`)

  const qs = extraQuery ? `&${extraQuery.replace(/^\?/, '').replace(/^&/, '')}` : ''
  const publicPath = `/api/${encodeURIComponent(relatedSlug)}?limit=100${qs}`
  const publicRes = await fetch(publicPath, { headers })
  if (publicRes.ok) {
    const payload = (await publicRes.json()) as { data?: RelatedRow[] }
    return payload.data ?? []
  }

  if (publicRes.status !== 403 && publicRes.status !== 401) {
    throw new Error(`Failed to load related (${publicRes.status})`)
  }

  const resources = await api<Resource[]>('/admin/api/resources')
  const related = resources.find((r) => r.slug === relatedSlug || r.contentTypeSlug === relatedSlug)
  if (!related) {
    throw new Error(`Related resource not found: ${relatedSlug}`)
  }

  const adminQs = new URLSearchParams({ limit: '100' })
  if (extraQuery) {
    for (const part of extraQuery.split('&')) {
      const [k, v] = part.split('=')
      if (k) adminQs.set(decodeURIComponent(k), decodeURIComponent(v ?? ''))
    }
  }
  const page = await apiPage<RelatedRow>(
    `/admin/api/resources/${related.id}/entries?${adminQs.toString()}`,
  )
  return page.data
}

function entryLabel(row: RelatedRow, labelField: string): string {
  const raw = row[labelField]
  if (raw == null || raw === '') return String(row.id)
  return String(raw)
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
  const [options, setOptions] = useState<RelatedRow[]>([])
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    if (!relatedSlug) {
      setOptions([])
      return
    }
    if (cardinality === 'oneToMany' && !entryId) {
      setOptions([])
      return
    }

    let cancelled = false
    setLoading(true)
    setError(null)

    const filterQs =
      cardinality === 'oneToMany' && foreignKey && entryId
        ? `filter[${encodeURIComponent(foreignKey)}]=${encodeURIComponent(String(entryId))}`
        : ''

    void fetchRelatedList(relatedSlug, filterQs)
      .then((rows) => {
        if (!cancelled) setOptions(rows)
      })
      .catch((err) => {
        if (!cancelled) {
          setOptions([])
          setError(err instanceof Error ? err.message : t('common.requestFailed'))
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [relatedSlug, cardinality, foreignKey, entryId, t])

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
      <select
        id={id}
        className={controlClass}
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
      </select>
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
    return (
      <MediaFieldPicker
        id={id}
        value={value}
        disabled={disabled}
        accept={field.type === 'image' ? 'image/*' : undefined}
        onChange={(mediaId) => set(field.name, mediaId)}
      />
    )
  }

  if (field.type === 'text' || field.type === 'json') {
    return (
      <textarea
        id={id}
        className={cn(controlClass, 'h-24 py-2')}
        disabled={disabled}
        value={typeof value === 'string' ? value : value == null ? '' : JSON.stringify(value)}
        onChange={(e) => set(field.name, e.target.value)}
      />
    )
  }

  if (field.type === 'enum') {
    const options = Array.isArray(field.config.options) ? field.config.options.map(String) : []
    return (
      <select
        id={id}
        className={controlClass}
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
      </select>
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
