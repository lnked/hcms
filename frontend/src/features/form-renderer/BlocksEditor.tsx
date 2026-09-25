import { clsx } from 'clsx'
import { ChevronDown, ChevronUp, Copy, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { FormRenderer, emptyValues, type EntryValues } from '@/features/form-renderer/FormRenderer'
import { useI18n } from '@/i18n'
import { sliceFieldErrors } from '@/lib/formErrors'
import styles from './BlocksEditor.module.css'
import type { FieldErrors } from '@/lib/formErrors'
import type { FieldTypeName, SchemaField } from '@/types/field'

export type BlockItem = { type: string } & Record<string, unknown>

interface BlocksEditorProps {
  id: string
  field: SchemaField
  value: unknown
  disabled?: boolean
  errors?: FieldErrors
  onChange: (next: BlockItem[]) => void
}

type ComponentDef = {
  label: string
  description: string
  fields: SchemaField[]
}

function parseBlocks(value: unknown): BlockItem[] {
  if (Array.isArray(value)) {
    return value.filter((b): b is BlockItem => {
      return typeof b === 'object' && b !== null && typeof (b as BlockItem).type === 'string'
    })
  }
  if (typeof value === 'string' && value.trim() !== '') {
    try {
      return parseBlocks(JSON.parse(value) as unknown)
    } catch {
      return []
    }
  }
  return []
}

function nestedFieldFromRow(row: Record<string, unknown>, i: number): SchemaField {
  const name = typeof row.name === 'string' ? row.name : `field_${i}`
  const nestedType: FieldTypeName = typeof row.type === 'string' ? row.type : 'string'
  return {
    name,
    type: nestedType === 'blocks' ? 'string' : nestedType,
    sortOrder: i,
    label: typeof row.label === 'string' && row.label !== '' ? row.label : name,
    description: typeof row.description === 'string' ? row.description : null,
    required: Boolean(row.required),
    nullable: row.nullable !== false,
    unique: false,
    indexed: false,
    readonly: false,
    hidden: false,
    searchable: false,
    sortable: false,
    filterable: false,
    readable: true,
    writable: true,
    config:
      row.config && typeof row.config === 'object' && !Array.isArray(row.config)
        ? (row.config as Record<string, unknown>)
        : {},
  } satisfies SchemaField
}

/** Supports legacy `type → FieldSpec[]` and `{ label?, description?, fields }`. */
export function resolveComponentDefs(field: SchemaField): Record<string, ComponentDef> {
  const raw = field.config.components
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return {}
  const out: Record<string, ComponentDef> = {}
  for (const [type, entry] of Object.entries(raw as Record<string, unknown>)) {
    if (Array.isArray(entry)) {
      out[type] = {
        label: type,
        description: '',
        fields: entry.map((f, i) => {
          const row = f && typeof f === 'object' ? (f as Record<string, unknown>) : {}
          return nestedFieldFromRow(row, i)
        }),
      }
      continue
    }
    if (!entry || typeof entry !== 'object') continue
    const obj = entry as Record<string, unknown>
    const fieldsRaw = Array.isArray(obj.fields) ? obj.fields : null
    if (!fieldsRaw) continue
    out[type] = {
      label: typeof obj.label === 'string' && obj.label !== '' ? obj.label : type,
      description: typeof obj.description === 'string' ? obj.description : '',
      fields: fieldsRaw.map((f, i) => {
        const row = f && typeof f === 'object' ? (f as Record<string, unknown>) : {}
        return nestedFieldFromRow(row, i)
      }),
    }
  }
  return out
}

function newClientId(): string {
  return crypto.randomUUID()
}

function summaryFor(block: BlockItem, specs: SchemaField[]): string {
  for (const key of ['title', 'heading', 'label', 'name']) {
    const v = block[key]
    if (typeof v === 'string' && v.trim() !== '') return v.trim()
  }
  for (const spec of specs) {
    if (spec.type !== 'string' && spec.type !== 'text') continue
    const v = block[spec.name]
    if (typeof v === 'string' && v.trim() !== '') return v.trim()
  }
  return ''
}

export function BlocksEditor({
  id,
  field,
  value,
  disabled,
  errors = {},
  onChange,
}: BlocksEditorProps) {
  const { t } = useI18n()
  const components = resolveComponentDefs(field)
  const types = Object.keys(components)
  const blocks = parseBlocks(value)
  const [clientIds, setClientIds] = useState<string[]>(() =>
    Array.from({ length: blocks.length }, () => newClientId()),
  )
  const [collapsed, setCollapsed] = useState<Record<string, boolean>>({})

  if (clientIds.length !== blocks.length) {
    const next =
      clientIds.length < blocks.length
        ? [
            ...clientIds,
            ...Array.from({ length: blocks.length - clientIds.length }, () => newClientId()),
          ]
        : clientIds.slice(0, blocks.length)
    setClientIds(next)
  }

  function updateAt(index: number, nextValues: EntryValues) {
    const current = blocks[index]
    if (!current) return
    const { type } = current
    onChange(blocks.map((b, i) => (i === index ? { ...nextValues, type } : b)))
  }

  function move(index: number, dir: -1 | 1) {
    const to = index + dir
    if (to < 0 || to >= blocks.length) return
    const next = [...blocks]
    const tmp = next[index]
    const other = next[to]
    if (tmp === undefined || other === undefined) return
    next[index] = other
    next[to] = tmp
    setClientIds((prev) => {
      const ids = [...prev]
      const idTmp = ids[index]
      const idOther = ids[to]
      if (idTmp === undefined || idOther === undefined) return prev
      ids[index] = idOther
      ids[to] = idTmp
      return ids
    })
    onChange(next)
  }

  function removeAt(index: number) {
    setClientIds((prev) => prev.filter((_, i) => i !== index))
    onChange(blocks.filter((_, i) => i !== index))
  }

  function duplicateAt(index: number) {
    const block = blocks[index]
    if (!block) return
    const copy = { ...block }
    const next = [...blocks]
    next.splice(index + 1, 0, copy)
    setClientIds((prev) => {
      const ids = [...prev]
      ids.splice(index + 1, 0, newClientId())
      return ids
    })
    onChange(next)
  }

  function add(type: string) {
    const def = components[type]
    const specs = def?.fields ?? []
    setClientIds((prev) => [...prev, newClientId()])
    onChange([...blocks, { type, ...emptyValues(specs) }])
  }

  if (types.length === 0) {
    return <p className={styles.hint}>{t('entries.blocksNoComponents')}</p>
  }

  return (
    <div className={styles.root} id={id}>
      {blocks.map((block, index) => {
        const def = components[block.type]
        const specs = def?.fields ?? []
        const unknown = !def
        const clientId = clientIds[index] ?? `${field.name}-${index}`
        const isCollapsed = collapsed[clientId] === true
        const { type: _type, ...fieldValues } = block
        const blockErrors = sliceFieldErrors(errors, `${field.name}.${index}`)
        const label = def?.label ?? block.type
        const summary = summaryFor(block, specs)

        return (
          <div key={clientId} className={styles.block}>
            <div className={styles.blockHeader}>
              <button
                type="button"
                className={styles.blockToggle}
                disabled={disabled}
                aria-expanded={!isCollapsed}
                onClick={() => setCollapsed((prev) => ({ ...prev, [clientId]: !isCollapsed }))}
              >
                <span className={styles.blockType}>{label}</span>
                {summary ? <span className={styles.blockSummary}>{summary}</span> : null}
                {unknown ? (
                  <span className={styles.orphan}>{t('entries.blocksUnknownType')}</span>
                ) : null}
              </button>
              <div className={styles.blockActions}>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={disabled || index === 0}
                  aria-label={t('entries.blocksMoveUp')}
                  onClick={() => move(index, -1)}
                >
                  <ChevronUp className={styles.icon} />
                </Button>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={disabled || index >= blocks.length - 1}
                  aria-label={t('entries.blocksMoveDown')}
                  onClick={() => move(index, 1)}
                >
                  <ChevronDown className={styles.icon} />
                </Button>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={disabled || unknown}
                  aria-label={t('entries.blocksDuplicate')}
                  onClick={() => duplicateAt(index)}
                >
                  <Copy className={styles.icon} />
                </Button>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={disabled}
                  aria-label={t('entries.blocksRemove')}
                  onClick={() => removeAt(index)}
                >
                  <Trash2 className={styles.icon} />
                </Button>
              </div>
            </div>
            {!isCollapsed ? (
              unknown ? (
                <p className={styles.hint}>{t('entries.blocksUnknownTypeHint')}</p>
              ) : specs.length > 0 ? (
                <FormRenderer
                  fields={specs}
                  values={fieldValues}
                  disabled={disabled}
                  errors={blockErrors}
                  idPrefix={`${field.name}-${index}-`}
                  onChange={(next) => updateAt(index, next)}
                />
              ) : (
                <p className={styles.hint}>{t('entries.blocksEmptyComponent')}</p>
              )
            ) : null}
          </div>
        )
      })}
      <div className={clsx(styles.addRow)}>
        <Label htmlFor={`${id}-add`}>{t('entries.blocksAdd')}</Label>
        <Select
          id={`${id}-add`}
          disabled={disabled}
          value=""
          onChange={(e) => {
            const type = e.target.value
            if (type) add(type)
          }}
        >
          <option value="">{t('entries.blocksAddPlaceholder')}</option>
          {types.map((type) => (
            <option key={type} value={type}>
              {components[type]?.label ?? type}
            </option>
          ))}
        </Select>
      </div>
    </div>
  )
}
