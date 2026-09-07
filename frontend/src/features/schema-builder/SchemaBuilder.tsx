import { useLayoutEffect, useRef, useState, type DragEvent } from 'react'
import { useQuery } from '@tanstack/react-query'
import { GripVertical, Plus, Settings2, Trash2 } from 'lucide-react'
import { AnchorGrid } from '@/components/AnchorGrid'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import {
  emptyField,
  FIELD_TYPES,
  type FieldTypeName,
  type ImageSizeConfig,
  type SchemaField,
} from '@/types/field'
import { cn } from '@/lib/utils'

interface SchemaBuilderProps {
  schema: SchemaField[]
  onChange: (schema: SchemaField[]) => void
}

const selectClass = 'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm'
const LIST_GAP_PX = 8

export function SchemaBuilder({ schema, onChange }: SchemaBuilderProps) {
  const { t } = useI18n()
  const [editingIndex, setEditingIndex] = useState<number | null>(null)
  const [dragIndex, setDragIndex] = useState<number | null>(null)
  const [overIndex, setOverIndex] = useState<number | null>(null)
  const [dragHeight, setDragHeight] = useState(0)
  const dragIndexRef = useRef<number | null>(null)
  const dragImageRef = useRef<HTMLElement | null>(null)
  const rowRefs = useRef<(HTMLLIElement | null)[]>([])
  const editFormRef = useRef<HTMLDivElement>(null)
  const scrollToEditRef = useRef(false)

  const fieldTypesQuery = useQuery({
    queryKey: ['field-types'],
    queryFn: () => api<string[]>('/admin/api/field-types'),
    staleTime: 60_000,
  })
  const fieldTypes: FieldTypeName[] =
    fieldTypesQuery.data && fieldTypesQuery.data.length > 0
      ? (fieldTypesQuery.data as FieldTypeName[])
      : FIELD_TYPES

  useLayoutEffect(() => {
    if (!scrollToEditRef.current || editingIndex === null || !editFormRef.current) return
    scrollToEditRef.current = false
    editFormRef.current.scrollIntoView?.({ behavior: 'smooth', block: 'start' })
  }, [editingIndex, schema.length])

  function updateAt(index: number, patch: Partial<SchemaField>) {
    onChange(schema.map((field, i) => (i === index ? { ...field, ...patch } : field)))
  }

  function addField() {
    const next = [...schema, emptyField('string', schema.length)]
    onChange(next)
    scrollToEditRef.current = true
    setEditingIndex(next.length - 1)
  }

  function removeAt(index: number) {
    onChange(schema.filter((_, i) => i !== index).map((field, i) => ({ ...field, sortOrder: i })))
    if (editingIndex === index) {
      setEditingIndex(null)
    }
  }

  function reorder(from: number, to: number) {
    if (from === to || from < 0 || to < 0 || from >= schema.length || to >= schema.length) return
    const next = [...schema]
    const [moved] = next.splice(from, 1)
    next.splice(to, 0, moved)
    onChange(next.map((field, i) => ({ ...field, sortOrder: i })))
    setEditingIndex((current) => {
      if (current === null) return null
      if (current === from) return to
      if (from < current && to >= current) return current - 1
      if (from > current && to <= current) return current + 1
      return current
    })
  }

  function clearDragState() {
    dragIndexRef.current = null
    dragImageRef.current?.remove()
    dragImageRef.current = null
    setDragIndex(null)
    setOverIndex(null)
    setDragHeight(0)
  }

  function rowShiftY(index: number): number {
    if (dragIndex === null || overIndex === null || dragIndex === overIndex || dragHeight <= 0) {
      return 0
    }
    const delta = dragHeight + LIST_GAP_PX
    if (dragIndex < overIndex) {
      if (index > dragIndex && index <= overIndex) return -delta
    } else if (index >= overIndex && index < dragIndex) {
      return delta
    }
    return 0
  }

  function onGripDragStart(index: number, event: DragEvent<HTMLButtonElement>) {
    dragIndexRef.current = index
    event.dataTransfer.effectAllowed = 'move'
    event.dataTransfer.setData('text/plain', String(index))

    const row = rowRefs.current[index]
    if (row) {
      const rect = row.getBoundingClientRect()
      const clone = row.cloneNode(true) as HTMLElement
      clone.style.width = `${rect.width}px`
      clone.style.position = 'fixed'
      clone.style.top = '-9999px'
      clone.style.left = '-9999px'
      clone.style.margin = '0'
      clone.style.opacity = '0.96'
      clone.style.boxShadow = '0 16px 40px rgba(15, 23, 42, 0.18)'
      clone.style.pointerEvents = 'none'
      clone.style.transform = 'rotate(1.5deg)'
      clone.style.zIndex = '9999'
      document.body.appendChild(clone)
      dragImageRef.current = clone
      event.dataTransfer.setDragImage(clone, event.clientX - rect.left, event.clientY - rect.top)
      setDragHeight(rect.height)
    }

    // Defer paint so React re-render does not cancel the native drag.
    requestAnimationFrame(() => {
      setDragIndex(index)
      setOverIndex(index)
    })
  }

  function onGripDragEnd() {
    clearDragState()
  }

  function onRowDragOver(index: number, event: DragEvent<HTMLLIElement>) {
    event.preventDefault()
    event.dataTransfer.dropEffect = 'move'
    if (overIndex !== index) setOverIndex(index)
  }

  function onRowDrop(index: number, event: DragEvent<HTMLLIElement>) {
    event.preventDefault()
    const raw = event.dataTransfer.getData('text/plain')
    const from = dragIndexRef.current ?? (raw === '' ? null : Number(raw))
    clearDragState()
    if (from === null || Number.isNaN(from)) return
    reorder(from, index)
  }

  function changeType(index: number, type: FieldTypeName) {
    const field = schema[index]
    const base = emptyField(type, field.sortOrder)
    updateAt(index, {
      ...base,
      id: field.id,
      clientKey: field.clientKey ?? base.clientKey,
      name: field.name,
      label: field.label,
      description: field.description,
    })
  }

  function patchConfig(index: number, patch: Record<string, unknown>) {
    const field = schema[index]
    const nextConfig = { ...field.config, ...patch }
    const cardinality = nextConfig.cardinality === 'oneToMany' ? 'oneToMany' : 'manyToOne'
    updateAt(index, {
      config: nextConfig,
      writable: field.type === 'relation' && cardinality === 'oneToMany' ? false : field.writable,
    })
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
        {schema.map((field, index) => {
          const shiftY = rowShiftY(index)
          const isDragging = dragIndex === index
          return (
            <li
              key={field.id != null ? `field-${field.id}` : (field.clientKey ?? `draft-${index}`)}
              ref={(node) => {
                rowRefs.current[index] = node
              }}
              onDragOver={(e) => onRowDragOver(index, e)}
              onDrop={(e) => onRowDrop(index, e)}
              style={{
                transform: shiftY ? `translateY(${shiftY}px)` : undefined,
              }}
              className={cn(
                'rounded-lg border bg-card will-change-transform',
                // Animate only while dragging so drop + DOM reorder don't double-shift.
                dragIndex !== null && 'transition-transform duration-200 ease-out',
                isDragging && 'border-dashed opacity-40 shadow-none',
                overIndex === index &&
                  dragIndex !== null &&
                  dragIndex !== index &&
                  'border-primary',
              )}
            >
              <div className="flex items-center gap-2 px-3 py-2">
                <button
                  type="button"
                  draggable
                  aria-label={t('schema.reorder')}
                  title={t('schema.reorder')}
                  className="inline-flex h-8 w-8 shrink-0 cursor-grab items-center justify-center rounded-md text-muted-foreground hover:bg-accent active:cursor-grabbing"
                  onDragStart={(e) => onGripDragStart(index, e)}
                  onDragEnd={onGripDragEnd}
                >
                  <GripVertical className="h-4 w-4" />
                </button>
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
                <div
                  ref={editFormRef}
                  className="grid scroll-mt-4 gap-3 border-t p-3 md:grid-cols-2"
                >
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
                      className={selectClass}
                      value={field.type}
                      onChange={(e) => changeType(index, e.target.value as FieldTypeName)}
                    >
                      {fieldTypes.map((type) => (
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
                  <label className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={field.filterable}
                      onChange={(e) => updateAt(index, { filterable: e.target.checked })}
                    />
                    {t('schema.filterable')}
                  </label>
                  <label className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={field.readable}
                      onChange={(e) => updateAt(index, { readable: e.target.checked })}
                    />
                    {t('schema.readable')}
                  </label>
                  <label className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={field.writable}
                      disabled={
                        field.type === 'relation' && field.config.cardinality === 'oneToMany'
                      }
                      onChange={(e) => updateAt(index, { writable: e.target.checked })}
                    />
                    {t('schema.writable')}
                  </label>
                  <label className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={field.hidden}
                      onChange={(e) => updateAt(index, { hidden: e.target.checked })}
                    />
                    {t('schema.hidden')}
                  </label>
                  <label className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={field.readonly}
                      onChange={(e) => updateAt(index, { readonly: e.target.checked })}
                    />
                    {t('schema.readonly')}
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
                  {field.type === 'slug' ? (
                    <div className="space-y-2 md:col-span-2">
                      <Label>{t('schema.slug.associatedWith')}</Label>
                      <select
                        className={selectClass}
                        value={String(field.config.associatedWith ?? '')}
                        onChange={(e) => patchConfig(index, { associatedWith: e.target.value })}
                      >
                        <option value="">—</option>
                        {schema
                          .filter((candidate, candidateIndex) => {
                            if (candidateIndex === index) return false
                            return Boolean(candidate.name.trim())
                          })
                          .map((candidate) => (
                            <option key={candidate.name} value={candidate.name}>
                              {candidate.label || candidate.name}
                            </option>
                          ))}
                      </select>
                    </div>
                  ) : null}
                  {field.type === 'file' || field.type === 'image' ? (
                    <>
                      <label className="flex items-center gap-2 text-sm md:col-span-2">
                        <input
                          type="checkbox"
                          checked={Boolean(field.config.multiple)}
                          onChange={(e) => patchConfig(index, { multiple: e.target.checked })}
                        />
                        {t(
                          field.type === 'image' ? 'schema.image.multiple' : 'schema.file.multiple',
                        )}
                      </label>
                      <div className="space-y-2 md:col-span-2">
                        <Label>
                          {t(
                            field.type === 'image' ? 'schema.image.formats' : 'schema.file.formats',
                          )}
                        </Label>
                        <Input
                          value={
                            Array.isArray(field.config.formats)
                              ? (field.config.formats as string[]).join(', ')
                              : ''
                          }
                          onChange={(e) =>
                            patchConfig(index, {
                              formats: e.target.value
                                .split(',')
                                .map((part) => part.trim().replace(/^\./, '').toLowerCase())
                                .filter(Boolean),
                            })
                          }
                          placeholder={field.type === 'image' ? 'jpg, png, webp' : 'pdf, docx, zip'}
                        />
                        <p className="text-xs text-muted-foreground">
                          {t(
                            field.type === 'image'
                              ? 'schema.image.formatsHint'
                              : 'schema.file.formatsHint',
                          )}
                        </p>
                      </div>
                    </>
                  ) : null}
                  {field.type === 'image' ? (
                    <div className="space-y-3 md:col-span-2">
                      <div className="flex items-center justify-between gap-2">
                        <Label>{t('schema.image.sizes')}</Label>
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          onClick={() => {
                            const sizes = Array.isArray(field.config.sizes)
                              ? ([...field.config.sizes] as ImageSizeConfig[])
                              : []
                            sizes.push({
                              prefix: `size${sizes.length + 1}`,
                              width: 200,
                              height: 200,
                              mode: 'crop',
                              position: 'c',
                            })
                            patchConfig(index, { sizes })
                          }}
                        >
                          <Plus className="mr-1 h-3.5 w-3.5" />
                          {t('schema.image.addSize')}
                        </Button>
                      </div>
                      {(Array.isArray(field.config.sizes)
                        ? (field.config.sizes as ImageSizeConfig[])
                        : []
                      ).map((size, sizeIndex) => (
                        <div
                          key={`${size.prefix}-${sizeIndex}`}
                          className="flex flex-wrap items-end gap-2 rounded-md border border-border p-2"
                        >
                          <div className="space-y-1">
                            <Label className="text-xs">{t('schema.image.prefix')}</Label>
                            <Input
                              className="w-28"
                              value={size.prefix}
                              onChange={(e) => {
                                const sizes = [...(field.config.sizes as ImageSizeConfig[])]
                                sizes[sizeIndex] = {
                                  ...sizes[sizeIndex],
                                  prefix: e.target.value,
                                }
                                patchConfig(index, { sizes })
                              }}
                            />
                          </div>
                          <div className="space-y-1">
                            <Label className="text-xs">{t('schema.image.width')}</Label>
                            <Input
                              className="w-20"
                              type="number"
                              min={1}
                              value={size.width}
                              onChange={(e) => {
                                const sizes = [...(field.config.sizes as ImageSizeConfig[])]
                                sizes[sizeIndex] = {
                                  ...sizes[sizeIndex],
                                  width: Number(e.target.value) || 1,
                                }
                                patchConfig(index, { sizes })
                              }}
                            />
                          </div>
                          <div className="space-y-1">
                            <Label className="text-xs">{t('schema.image.height')}</Label>
                            <Input
                              className="w-20"
                              type="number"
                              min={1}
                              value={size.height}
                              onChange={(e) => {
                                const sizes = [...(field.config.sizes as ImageSizeConfig[])]
                                sizes[sizeIndex] = {
                                  ...sizes[sizeIndex],
                                  height: Number(e.target.value) || 1,
                                }
                                patchConfig(index, { sizes })
                              }}
                            />
                          </div>
                          <div className="space-y-1">
                            <Label className="text-xs">{t('schema.image.mode')}</Label>
                            <select
                              className={cn(selectClass, 'w-28')}
                              value={size.mode === 'resize' ? 'resize' : 'crop'}
                              onChange={(e) => {
                                const sizes = [...(field.config.sizes as ImageSizeConfig[])]
                                sizes[sizeIndex] = {
                                  ...sizes[sizeIndex],
                                  mode: e.target.value === 'resize' ? 'resize' : 'crop',
                                }
                                patchConfig(index, { sizes })
                              }}
                            >
                              <option value="crop">{t('schema.image.modeCrop')}</option>
                              <option value="resize">{t('schema.image.modeResize')}</option>
                            </select>
                          </div>
                          <div className="space-y-1">
                            <Label className="text-xs">{t('schema.image.position')}</Label>
                            <AnchorGrid
                              value={size.position || 'c'}
                              onChange={(position) => {
                                const sizes = [...(field.config.sizes as ImageSizeConfig[])]
                                sizes[sizeIndex] = { ...sizes[sizeIndex], position }
                                patchConfig(index, { sizes })
                              }}
                            />
                          </div>
                          <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            className="text-destructive"
                            onClick={() => {
                              const sizes = (field.config.sizes as ImageSizeConfig[]).filter(
                                (_, i) => i !== sizeIndex,
                              )
                              patchConfig(index, { sizes })
                            }}
                          >
                            {t('schema.image.removeSize')}
                          </Button>
                        </div>
                      ))}
                    </div>
                  ) : null}
                  {field.type === 'relation' ? (
                    <>
                      <div className="space-y-2">
                        <Label>{t('schema.relation.relatedSlug')}</Label>
                        <Input
                          value={String(field.config.relatedSlug ?? '')}
                          onChange={(e) => patchConfig(index, { relatedSlug: e.target.value })}
                          placeholder="posts"
                        />
                      </div>
                      <div className="space-y-2">
                        <Label>{t('schema.relation.cardinality')}</Label>
                        <select
                          className={selectClass}
                          value={
                            field.config.cardinality === 'oneToMany' ? 'oneToMany' : 'manyToOne'
                          }
                          onChange={(e) => {
                            const cardinality =
                              e.target.value === 'oneToMany' ? 'oneToMany' : 'manyToOne'
                            updateAt(index, {
                              config: { ...field.config, cardinality },
                              writable: cardinality === 'oneToMany' ? false : true,
                            })
                          }}
                        >
                          <option value="manyToOne">{t('schema.relation.manyToOne')}</option>
                          <option value="oneToMany">{t('schema.relation.oneToMany')}</option>
                        </select>
                      </div>
                      <div className="space-y-2">
                        <Label>{t('schema.relation.labelField')}</Label>
                        <Input
                          value={String(field.config.labelField ?? 'id')}
                          onChange={(e) => patchConfig(index, { labelField: e.target.value })}
                          placeholder="title"
                        />
                      </div>
                      {field.config.cardinality === 'oneToMany' ? (
                        <div className="space-y-2">
                          <Label>{t('schema.relation.foreignKey')}</Label>
                          <Input
                            value={String(field.config.foreignKey ?? '')}
                            onChange={(e) => patchConfig(index, { foreignKey: e.target.value })}
                            placeholder="post_id"
                          />
                        </div>
                      ) : null}
                    </>
                  ) : null}
                </div>
              ) : null}
            </li>
          )
        })}
      </ul>
    </div>
  )
}
