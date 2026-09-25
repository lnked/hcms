import { clsx } from 'clsx'
import { ChevronDown, ChevronUp, Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { FormRenderer, emptyValues, type EntryValues } from '@/features/form-renderer/FormRenderer'
import { useI18n } from '@/i18n'
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

function componentSpecs(field: SchemaField): Record<string, SchemaField[]> {
  const raw = field.config.components
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return {}
  const out: Record<string, SchemaField[]> = {}
  for (const [type, fields] of Object.entries(raw as Record<string, unknown>)) {
    if (!Array.isArray(fields)) continue
    out[type] = fields.map((f, i) => {
      const row = f && typeof f === 'object' ? (f as Record<string, unknown>) : {}
      const name = typeof row.name === 'string' ? row.name : `field_${i}`
      const nestedType = (
        typeof row.type === 'string' ? row.type : 'string'
      ) as FieldTypeName
      return {
        name,
        type: nestedType === 'blocks' ? 'string' : nestedType,
        sortOrder: i,
        label: typeof row.label === 'string' && row.label !== '' ? row.label : name,
        description: null,
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
    })
  }
  return out
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
  const components = componentSpecs(field)
  const types = Object.keys(components)
  const blocks = parseBlocks(value)

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
    onChange(next)
  }

  function removeAt(index: number) {
    onChange(blocks.filter((_, i) => i !== index))
  }

  function add(type: string) {
    const specs = components[type] ?? []
    onChange([...blocks, { type, ...emptyValues(specs) }])
  }

  if (types.length === 0) {
    return <p className={styles.hint}>{t('entries.blocksNoComponents')}</p>
  }

  return (
    <div className={styles.root} id={id}>
      {blocks.map((block, index) => {
        const specs = components[block.type] ?? []
        const { type: _type, ...fieldValues } = block
        return (
          <div key={`${block.type}-${index}`} className={styles.block}>
            <div className={styles.blockHeader}>
              <span className={styles.blockType}>{block.type}</span>
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
                  disabled={disabled}
                  aria-label={t('entries.blocksRemove')}
                  onClick={() => removeAt(index)}
                >
                  <Trash2 className={styles.icon} />
                </Button>
              </div>
            </div>
            {specs.length > 0 ? (
              <FormRenderer
                fields={specs}
                values={fieldValues}
                disabled={disabled}
                errors={errors}
                onChange={(next) => updateAt(index, next)}
              />
            ) : (
              <p className={styles.hint}>{t('entries.blocksEmptyComponent')}</p>
            )}
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
              {type}
            </option>
          ))}
        </Select>
      </div>
    </div>
  )
}
