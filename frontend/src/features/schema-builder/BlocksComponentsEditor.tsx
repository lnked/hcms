import { clsx } from 'clsx'
import { ChevronDown, ChevronUp, Plus, Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { useI18n } from '@/i18n'
import { slugifyIdentifier } from '@/lib/slugify'
import styles from './BlocksComponentsEditor.module.css'
import type { FieldTypeName, SchemaField } from '@/types/field'

export type BlockNestedField = {
  name: string
  type: string
  required?: boolean
  nullable?: boolean
  label?: string
  config?: Record<string, unknown>
}

export type BlockComponentDef = {
  label: string
  fields: BlockNestedField[]
}

function readComponents(field: SchemaField): Record<string, BlockComponentDef> {
  const raw = field.config.components
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return {}
  const out: Record<string, BlockComponentDef> = {}
  for (const [type, entry] of Object.entries(raw as Record<string, unknown>)) {
    if (Array.isArray(entry)) {
      out[type] = {
        label: type,
        fields: entry.map((f) => {
          const row = f && typeof f === 'object' ? (f as Record<string, unknown>) : {}
          return {
            name: typeof row.name === 'string' ? row.name : '',
            type: typeof row.type === 'string' ? row.type : 'string',
            required: Boolean(row.required),
            nullable: row.nullable !== false,
            label: typeof row.label === 'string' ? row.label : '',
            config:
              row.config && typeof row.config === 'object' && !Array.isArray(row.config)
                ? (row.config as Record<string, unknown>)
                : {},
          }
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
      fields: fieldsRaw.map((f) => {
        const row = f && typeof f === 'object' ? (f as Record<string, unknown>) : {}
        return {
          name: typeof row.name === 'string' ? row.name : '',
          type: typeof row.type === 'string' ? row.type : 'string',
          required: Boolean(row.required),
          nullable: row.nullable !== false,
          label: typeof row.label === 'string' ? row.label : '',
          config:
            row.config && typeof row.config === 'object' && !Array.isArray(row.config)
              ? (row.config as Record<string, unknown>)
              : {},
        }
      }),
    }
  }
  return out
}

export function BlocksComponentsEditor({
  field,
  fieldTypes,
  onChange,
}: {
  field: SchemaField
  fieldTypes: FieldTypeName[]
  onChange: (components: Record<string, BlockComponentDef>) => void
}) {
  const { t } = useI18n()
  const components = readComponents(field)
  const entries = Object.entries(components)

  function setComponents(next: Record<string, BlockComponentDef>) {
    onChange(next)
  }

  function renameType(from: string, to: string) {
    const trimmed = slugifyIdentifier(to)
    if (trimmed === '' || trimmed === from) return
    if (components[trimmed]) return
    const next: Record<string, BlockComponentDef> = {}
    for (const [key, def] of Object.entries(components)) {
      next[key === from ? trimmed : key] = def
    }
    setComponents(next)
  }

  function addComponent() {
    let name = 'block'
    let i = 1
    while (components[name]) {
      name = `block_${i}`
      i += 1
    }
    setComponents({
      ...components,
      [name]: {
        label: 'Block',
        fields: [
          {
            name: 'title',
            type: 'string',
            required: true,
            nullable: false,
            label: 'Title',
            config: { maxLength: 255 },
          },
        ],
      },
    })
  }

  function removeComponent(type: string) {
    const next = { ...components }
    delete next[type]
    setComponents(next)
  }

  function patchComponent(type: string, patch: Partial<BlockComponentDef>) {
    const current = components[type]
    if (!current) return
    setComponents({ ...components, [type]: { ...current, ...patch } })
  }

  function updateNested(type: string, index: number, patch: Partial<BlockNestedField>) {
    const current = components[type]
    if (!current) return
    const list = [...current.fields]
    const row = list[index]
    if (!row) return
    list[index] = { ...row, ...patch }
    patchComponent(type, { fields: list })
  }

  function moveNested(type: string, index: number, dir: -1 | 1) {
    const current = components[type]
    if (!current) return
    const to = index + dir
    if (to < 0 || to >= current.fields.length) return
    const list = [...current.fields]
    const a = list[index]
    const b = list[to]
    if (!a || !b) return
    list[index] = b
    list[to] = a
    patchComponent(type, { fields: list })
  }

  function addNested(type: string) {
    const current = components[type]
    if (!current) return
    patchComponent(type, {
      fields: [
        ...current.fields,
        { name: '', type: 'string', required: false, nullable: true, label: '', config: {} },
      ],
    })
  }

  function removeNested(type: string, index: number) {
    const current = components[type]
    if (!current) return
    patchComponent(type, { fields: current.fields.filter((_, i) => i !== index) })
  }

  return (
    <div className={clsx(styles.root)}>
      <div className={styles.header}>
        <Label>{t('schema.blocks.components')}</Label>
        <Button type="button" size="sm" variant="outline" onClick={addComponent}>
          <Plus className={styles.iconSm} />
          {t('schema.blocks.addComponent')}
        </Button>
      </div>
      <p className={styles.hint}>{t('schema.blocks.componentsHint')}</p>
      {entries.length === 0 ? <p className={styles.hint}>{t('schema.blocks.empty')}</p> : null}
      {entries.map(([type, def]) => (
        <div key={type} className={styles.component}>
          <div className={styles.componentHeader}>
            <Input
              defaultValue={type}
              key={`type-${type}`}
              aria-label={t('schema.blocks.componentType')}
              onBlur={(e) => renameType(type, e.target.value)}
            />
            <Input
              value={def.label}
              aria-label={t('schema.blocks.componentLabel')}
              placeholder={t('schema.blocks.componentLabel')}
              onChange={(e) => patchComponent(type, { label: e.target.value })}
            />
            <Button
              type="button"
              size="sm"
              variant="outline"
              aria-label={t('schema.blocks.removeComponent')}
              onClick={() => removeComponent(type)}
            >
              <Trash2 className={styles.iconSm} />
            </Button>
          </div>
          {def.fields.map((nf, ni) => (
            <div key={ni} className={styles.nestedGroup}>
              <div className={styles.nestedRow}>
                <Input
                  placeholder={t('common.name')}
                  value={nf.name}
                  onChange={(e) =>
                    updateNested(type, ni, { name: slugifyIdentifier(e.target.value) })
                  }
                />
                <Select
                  value={nf.type}
                  onChange={(e) =>
                    updateNested(type, ni, { type: e.target.value, config: {} })
                  }
                >
                  {fieldTypes.map((ft) => (
                    <option key={ft} value={ft}>
                      {ft}
                    </option>
                  ))}
                </Select>
                <Input
                  placeholder={t('common.label')}
                  value={nf.label ?? ''}
                  onChange={(e) => updateNested(type, ni, { label: e.target.value })}
                />
                <label className={styles.checkLabel}>
                  <input
                    type="checkbox"
                    checked={Boolean(nf.required)}
                    onChange={(e) => updateNested(type, ni, { required: e.target.checked })}
                  />
                  {t('common.required')}
                </label>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={ni === 0}
                  aria-label={t('schema.blocks.moveFieldUp')}
                  onClick={() => moveNested(type, ni, -1)}
                >
                  <ChevronUp className={styles.iconSm} />
                </Button>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={ni >= def.fields.length - 1}
                  aria-label={t('schema.blocks.moveFieldDown')}
                  onClick={() => moveNested(type, ni, 1)}
                >
                  <ChevronDown className={styles.iconSm} />
                </Button>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  aria-label={t('common.delete')}
                  onClick={() => removeNested(type, ni)}
                >
                  <Trash2 className={styles.iconSm} />
                </Button>
              </div>
              {nf.type === 'enum' ? (
                <div className={styles.nestedConfig}>
                  <Label>{t('schema.options')}</Label>
                  <Input
                    value={
                      Array.isArray(nf.config?.options)
                        ? (nf.config.options as unknown[]).map(String).join(', ')
                        : ''
                    }
                    onChange={(e) =>
                      updateNested(type, ni, {
                        config: {
                          ...nf.config,
                          options: e.target.value
                            .split(',')
                            .map((part) => part.trim())
                            .filter(Boolean),
                        },
                      })
                    }
                  />
                </div>
              ) : null}
              {nf.type === 'string' || nf.type === 'slug' ? (
                <div className={styles.nestedConfig}>
                  <Label>{t('schema.maxLength')}</Label>
                  <Input
                    type="number"
                    min={1}
                    value={Number(nf.config?.maxLength ?? 255)}
                    onChange={(e) =>
                      updateNested(type, ni, {
                        config: {
                          ...nf.config,
                          maxLength: Number.parseInt(e.target.value, 10) || 255,
                        },
                      })
                    }
                  />
                </div>
              ) : null}
              {nf.type === 'relation' ? (
                <div className={styles.nestedConfig}>
                  <Label>{t('schema.relation.relatedSlug')}</Label>
                  <Input
                    value={typeof nf.config?.relatedSlug === 'string' ? nf.config.relatedSlug : ''}
                    onChange={(e) =>
                      updateNested(type, ni, {
                        config: {
                          ...nf.config,
                          relatedSlug: e.target.value,
                          cardinality: 'manyToOne',
                        },
                      })
                    }
                  />
                </div>
              ) : null}
              {nf.type === 'image' || nf.type === 'file' ? (
                <div className={styles.nestedConfig}>
                  <Label>{t('schema.image.formats')}</Label>
                  <Input
                    value={
                      Array.isArray(nf.config?.formats)
                        ? (nf.config.formats as unknown[]).map(String).join(', ')
                        : ''
                    }
                    placeholder="image/png, image/jpeg"
                    onChange={(e) =>
                      updateNested(type, ni, {
                        config: {
                          ...nf.config,
                          formats: e.target.value
                            .split(',')
                            .map((part) => part.trim())
                            .filter(Boolean),
                        },
                      })
                    }
                  />
                </div>
              ) : null}
            </div>
          ))}
          <Button type="button" size="sm" variant="outline" onClick={() => addNested(type)}>
            <Plus className={styles.iconSm} />
            {t('schema.blocks.addField')}
          </Button>
        </div>
      ))}
    </div>
  )
}
