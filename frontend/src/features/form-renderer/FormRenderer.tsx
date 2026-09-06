import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { MediaFieldPicker } from '@/features/media/MediaFieldPicker'
import { useI18n } from '@/i18n'
import type { SchemaField } from '@/types/field'
import { cn } from '@/lib/utils'

export type EntryValues = Record<string, unknown>

interface FormRendererProps {
  fields: SchemaField[]
  values: EntryValues
  onChange: (values: EntryValues) => void
  disabled?: boolean
}

const controlClass =
  'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring'

export function FormRenderer({ fields, values, onChange, disabled }: FormRendererProps) {
  const { t } = useI18n()
  const writable = fields
    .filter((f) => f.writable && !f.hidden && !f.readonly)
    .slice()
    .sort((a, b) => a.sortOrder - b.sortOrder)

  function set(name: string, value: unknown) {
    onChange({ ...values, [name]: value })
  }

  return (
    <div className="space-y-4">
      {writable.map((field) => {
        const id = `field-${field.name}`
        const value = values[field.name]
        return (
          <div key={field.name} className="space-y-1.5">
            <Label htmlFor={id}>
              {field.label || field.name}
              {field.required ? <span className="text-destructive"> *</span> : null}
            </Label>
            {field.description ? (
              <p className="text-xs text-muted-foreground">{field.description}</p>
            ) : null}
            {renderControl(field, id, value, disabled, set)}
          </div>
        )
      })}
      {writable.length === 0 ? (
        <p className="text-sm text-muted-foreground">{t('entries.noWritable')}</p>
      ) : null}
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
