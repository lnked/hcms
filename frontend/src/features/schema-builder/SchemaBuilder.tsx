import { useState } from 'react'
import { GripVertical, Plus, Settings2, Trash2 } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useI18n } from '@/i18n'
import { emptyField, FIELD_TYPES, type SchemaField } from '@/types/field'
import { cn } from '@/lib/utils'

interface SchemaBuilderProps {
  schema: SchemaField[]
  onChange: (schema: SchemaField[]) => void
}

export function SchemaBuilder({ schema, onChange }: SchemaBuilderProps) {
  const { t } = useI18n()
  const [editingIndex, setEditingIndex] = useState<number | null>(null)
  const [dragIndex, setDragIndex] = useState<number | null>(null)

  function updateAt(index: number, patch: Partial<SchemaField>) {
    onChange(schema.map((field, i) => (i === index ? { ...field, ...patch } : field)))
  }

  function addField() {
    const next = [...schema, emptyField('string', schema.length)]
    onChange(next)
    setEditingIndex(next.length - 1)
  }

  function removeAt(index: number) {
    onChange(schema.filter((_, i) => i !== index).map((field, i) => ({ ...field, sortOrder: i })))
    if (editingIndex === index) {
      setEditingIndex(null)
    }
  }

  function onDrop(targetIndex: number) {
    if (dragIndex === null || dragIndex === targetIndex) {
      setDragIndex(null)
      return
    }
    const next = [...schema]
    const [moved] = next.splice(dragIndex, 1)
    next.splice(targetIndex, 0, moved)
    onChange(next.map((field, i) => ({ ...field, sortOrder: i })))
    setDragIndex(null)
  }

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-semibold">{t('schema.fields')}</h2>
        <Button type="button" size="sm" onClick={addField}>
          <Plus className="h-4 w-4" />
          {t('schema.addField')}
        </Button>
      </div>

      {schema.length === 0 ? (
        <p className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
          {t('schema.empty')}
        </p>
      ) : null}

      <ul className="space-y-2">
        {schema.map((field, index) => (
          <li
            key={`${field.id ?? 'new'}-${index}`}
            draggable
            onDragStart={() => setDragIndex(index)}
            onDragOver={(e) => e.preventDefault()}
            onDrop={() => onDrop(index)}
            className={cn('rounded-lg border bg-card', dragIndex === index && 'opacity-60')}
          >
            <div className="flex items-center gap-2 px-3 py-2">
              <GripVertical className="h-4 w-4 cursor-grab text-muted-foreground" />
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-medium">
                    {field.label || field.name || t('schema.untitled')}
                  </span>
                  <Badge variant="outline">{field.type}</Badge>
                  {field.required ? (
                    <Badge>{t('common.required')}</Badge>
                  ) : (
                    <Badge variant="secondary">{t('common.optional')}</Badge>
                  )}
                </div>
                <p className="truncate font-mono text-xs text-muted-foreground">
                  {field.name || '—'}
                </p>
              </div>
              <Button
                type="button"
                size="icon"
                variant="ghost"
                onClick={() => setEditingIndex(editingIndex === index ? null : index)}
              >
                <Settings2 className="h-4 w-4" />
              </Button>
              <Button type="button" size="icon" variant="ghost" onClick={() => removeAt(index)}>
                <Trash2 className="h-4 w-4 text-destructive" />
              </Button>
            </div>

            {editingIndex === index ? (
              <div className="grid gap-3 border-t p-3 md:grid-cols-2">
                <div className="space-y-2">
                  <Label>{t('common.name')}</Label>
                  <Input
                    value={field.name}
                    onChange={(e) =>
                      updateAt(index, {
                        name: e.target.value,
                        label: field.label || e.target.value,
                      })
                    }
                    placeholder="title"
                  />
                </div>
                <div className="space-y-2">
                  <Label>{t('common.label')}</Label>
                  <Input
                    value={field.label}
                    onChange={(e) => updateAt(index, { label: e.target.value })}
                  />
                </div>
                <div className="space-y-2">
                  <Label>{t('common.type')}</Label>
                  <select
                    className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                    value={field.type}
                    onChange={(e) =>
                      updateAt(index, {
                        type: e.target.value,
                        config:
                          e.target.value === 'enum'
                            ? { options: ['draft', 'published'] }
                            : e.target.value === 'string'
                              ? { maxLength: 255 }
                              : {},
                      })
                    }
                  >
                    {FIELD_TYPES.map((type) => (
                      <option key={type} value={type}>
                        {type}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="space-y-2">
                  <Label>{t('common.description')}</Label>
                  <Input
                    value={field.description ?? ''}
                    onChange={(e) => updateAt(index, { description: e.target.value })}
                  />
                </div>
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={field.required}
                    onChange={(e) =>
                      updateAt(index, { required: e.target.checked, nullable: !e.target.checked })
                    }
                  />
                  {t('common.required')}
                </label>
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={field.unique}
                    onChange={(e) => updateAt(index, { unique: e.target.checked })}
                  />
                  {t('schema.unique')}
                </label>
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={field.searchable}
                    onChange={(e) => updateAt(index, { searchable: e.target.checked })}
                  />
                  {t('schema.searchable')}
                </label>
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={field.sortable}
                    onChange={(e) => updateAt(index, { sortable: e.target.checked })}
                  />
                  {t('schema.sortable')}
                </label>
                {field.type === 'enum' ? (
                  <div className="space-y-2 md:col-span-2">
                    <Label>{t('schema.options')}</Label>
                    <Input
                      value={
                        Array.isArray(field.config.options) ? field.config.options.join(', ') : ''
                      }
                      onChange={(e) =>
                        updateAt(index, {
                          config: {
                            ...field.config,
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
              </div>
            ) : null}
          </li>
        ))}
      </ul>
    </div>
  )
}
