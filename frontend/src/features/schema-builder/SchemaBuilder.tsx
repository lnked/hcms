import { clsx } from 'clsx'
import { Crop, GripVertical, Images, Plus, Settings2, Trash2 } from 'lucide-react'
import { useLayoutEffect, useRef, useState } from 'react'
import { AnchorPicker } from '@/components/AnchorPicker'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { controlFieldClass } from '@/components/ui/control'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { useMediaEncodeCapabilities } from '@/features/media/useMediaEncodeCapabilities'
import { BlocksComponentsEditor } from '@/features/schema-builder/BlocksComponentsEditor'
import { useFieldTypes } from '@/features/schema-builder/useFieldTypes'
import { useSchemaFieldDrag } from '@/features/schema-builder/useSchemaFieldDrag'
import { useI18n } from '@/i18n'
import { configString } from '@/lib/coerce'
import { slugifyIdentifier } from '@/lib/slugify'
import {
  DEFAULT_DATE_FORMAT,
  DEFAULT_DATETIME_FORMAT,
  emptyField,
  FIELD_TYPES,
  type FieldTypeName,
  type ImageSizeConfig,
  type SchemaField,
} from '@/types/field'
import styles from './SchemaBuilder.module.css'

interface SchemaBuilderProps {
  schema: SchemaField[]
  onChange: (schema: SchemaField[]) => void
}

export function SchemaBuilder({ schema, onChange }: SchemaBuilderProps) {
  const { t } = useI18n()
  const [editingIndex, setEditingIndex] = useState<number | null>(null)
  const editFormRef = useRef<HTMLDivElement>(null)
  const scrollToEditRef = useRef(false)

  const { fieldTypeDescriptors, fieldTypes, descriptorByName } = useFieldTypes()
  const encodeCaps = useMediaEncodeCapabilities()

  function reorder(from: number, to: number) {
    if (from === to || from < 0 || to < 0 || from >= schema.length || to >= schema.length) return
    const next = [...schema]
    const [moved] = next.splice(from, 1)
    if (moved === undefined) return
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

  const {
    listRef,
    rowRefs,
    dragIndex,
    overIndex,
    rowShiftY,
    onGripDragStart,
    onGripDragEnd,
    onListDragOver,
    onListDrop,
  } = useSchemaFieldDrag({ itemCount: schema.length, onReorder: reorder })

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

  function changeType(index: number, type: FieldTypeName) {
    const field = schema[index]
    if (field === undefined) return
    const base = emptyField(type, field.sortOrder, descriptorByName.get(type))
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
    if (field === undefined) return
    const nextConfig = { ...field.config, ...patch }
    const cardinality =
      typeof nextConfig.cardinality === 'string' ? nextConfig.cardinality : 'manyToOne'
    updateAt(index, {
      config: nextConfig,
      writable: field.type === 'relation' && cardinality === 'oneToMany' ? false : field.writable,
    })
  }

  return (
    <div className={styles.root}>
      <div className={styles.header}>
        <h2 className={styles.title}>{t('schema.fields')}</h2>
        <Button type="button" size="sm" onClick={addField}>
          <Plus className={styles.icon} />
          {t('schema.addField')}
        </Button>
      </div>

      {schema.length === 0 ? <p className={styles.empty}>{t('schema.empty')}</p> : null}

      <ul ref={listRef} className={styles.list} onDragOver={onListDragOver} onDrop={onListDrop}>
        {schema.map((field, index) => {
          const shiftY = rowShiftY(index)
          const isDragging = dragIndex === index
          return (
            <li
              key={field.id != null ? `field-${field.id}` : (field.clientKey ?? `draft-${index}`)}
              ref={(node) => {
                rowRefs.current[index] = node
              }}
              style={{
                transform: shiftY ? `translateY(${shiftY}px)` : undefined,
              }}
              className={clsx(
                styles.row,
                // Animate only while dragging so drop + DOM reorder don't double-shift.
                dragIndex !== null && styles.rowAnimating,
                isDragging && styles.rowDragging,
                overIndex === index &&
                  dragIndex !== null &&
                  dragIndex !== index &&
                  styles.rowDropTarget,
              )}
            >
              <div className={styles.rowHeader}>
                <button
                  type="button"
                  draggable
                  aria-label={t('schema.reorder')}
                  title={t('schema.reorder')}
                  className={styles.grip}
                  onDragStart={(e) => onGripDragStart(index, e)}
                  onDragEnd={onGripDragEnd}
                >
                  <GripVertical className={styles.icon} />
                </button>
                <div className={styles.rowMain}>
                  <div className={styles.rowTitleLine}>
                    <span className={styles.rowLabel}>
                      {field.label || field.name || t('schema.untitled')}
                    </span>
                    <Badge variant="outline">{field.type}</Badge>
                    {field.required ? (
                      <Badge>{t('common.required')}</Badge>
                    ) : (
                      <Badge variant="secondary">{t('common.optional')}</Badge>
                    )}
                  </div>
                  <p className={styles.rowName}>{field.name || '—'}</p>
                </div>
                <Button
                  type="button"
                  size="icon"
                  variant="ghost"
                  onClick={() => setEditingIndex(editingIndex === index ? null : index)}
                >
                  <Settings2 className={styles.icon} />
                </Button>
                <Button type="button" size="icon" variant="ghost" onClick={() => removeAt(index)}>
                  <Trash2 className={styles.iconDestructive} />
                </Button>
              </div>

              {editingIndex === index ? (
                <div ref={editFormRef} className={styles.editForm}>
                  <div className={styles.field}>
                    <Label>{t('common.name')}</Label>
                    <Input
                      value={field.name}
                      maxLength={64}
                      onChange={(e) =>
                        updateAt(index, {
                          name: slugifyIdentifier(e.target.value, 64),
                          label: field.label || e.target.value,
                        })
                      }
                      placeholder="title"
                    />
                  </div>
                  <div className={styles.field}>
                    <Label>{t('common.label')}</Label>
                    <Input
                      value={field.label}
                      onChange={(e) => updateAt(index, { label: e.target.value })}
                    />
                  </div>
                  <div className={styles.field}>
                    <Label>{t('common.type')}</Label>
                    <Select value={field.type} onChange={(e) => changeType(index, e.target.value)}>
                      {fieldTypeDescriptors.map((descriptor) => (
                        <option key={descriptor.name} value={descriptor.name}>
                          {descriptor.label !== descriptor.name
                            ? `${descriptor.label} (${descriptor.name})`
                            : descriptor.name}
                        </option>
                      ))}
                    </Select>
                  </div>
                  <div className={styles.field}>
                    <Label>{t('common.description')}</Label>
                    <Input
                      value={field.description ?? ''}
                      onChange={(e) => updateAt(index, { description: e.target.value })}
                    />
                  </div>
                  <label className={styles.checkLabel}>
                    <input
                      type="checkbox"
                      checked={field.required}
                      onChange={(e) =>
                        updateAt(index, { required: e.target.checked, nullable: !e.target.checked })
                      }
                    />
                    {t('common.required')}
                  </label>
                  <label className={styles.checkLabel}>
                    <input
                      type="checkbox"
                      checked={field.unique}
                      onChange={(e) => updateAt(index, { unique: e.target.checked })}
                    />
                    {t('schema.unique')}
                  </label>
                  <label className={styles.checkLabel}>
                    <input
                      type="checkbox"
                      checked={field.searchable}
                      onChange={(e) => updateAt(index, { searchable: e.target.checked })}
                    />
                    {t('schema.searchable')}
                  </label>
                  <label className={styles.checkLabel}>
                    <input
                      type="checkbox"
                      checked={field.sortable}
                      onChange={(e) => updateAt(index, { sortable: e.target.checked })}
                    />
                    {t('schema.sortable')}
                  </label>
                  <label className={styles.checkLabel}>
                    <input
                      type="checkbox"
                      checked={field.filterable}
                      onChange={(e) => updateAt(index, { filterable: e.target.checked })}
                    />
                    {t('schema.filterable')}
                  </label>
                  <label className={styles.checkLabel}>
                    <input
                      type="checkbox"
                      checked={field.readable}
                      onChange={(e) => updateAt(index, { readable: e.target.checked })}
                    />
                    {t('schema.readable')}
                  </label>
                  <label className={styles.checkLabel}>
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
                  <label className={styles.checkLabel}>
                    <input
                      type="checkbox"
                      checked={field.hidden}
                      onChange={(e) => updateAt(index, { hidden: e.target.checked })}
                    />
                    {t('schema.hidden')}
                  </label>
                  <label className={styles.checkLabel}>
                    <input
                      type="checkbox"
                      checked={field.readonly}
                      onChange={(e) => updateAt(index, { readonly: e.target.checked })}
                    />
                    {t('schema.readonly')}
                  </label>
                  {field.type === 'enum' ? (
                    <div className={clsx(styles.field, styles.span2)}>
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
                  {field.type === 'blocks' ? (
                    <BlocksComponentsEditor
                      field={field}
                      fieldTypes={fieldTypes.filter((ft) => ft !== 'blocks')}
                      onChange={(components) => patchConfig(index, { components })}
                    />
                  ) : null}
                  {field.type === 'date' || field.type === 'datetime' ? (
                    <div className={clsx(styles.field, styles.span2)}>
                      <Label>{t('schema.date.format')}</Label>
                      <Input
                        value={configString(field.config.format)}
                        maxLength={32}
                        placeholder={
                          field.type === 'date' ? DEFAULT_DATE_FORMAT : DEFAULT_DATETIME_FORMAT
                        }
                        onChange={(e) => patchConfig(index, { format: e.target.value })}
                      />
                      <p className={styles.hint}>{t('schema.date.formatHint')}</p>
                    </div>
                  ) : null}
                  {field.type === 'slug' ? (
                    <div className={clsx(styles.field, styles.span2)}>
                      <Label>{t('schema.slug.associatedWith')}</Label>
                      <Select
                        value={configString(field.config.associatedWith)}
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
                      </Select>
                    </div>
                  ) : null}
                  {field.type === 'file' || field.type === 'image' ? (
                    <>
                      <label className={clsx(styles.checkLabel, styles.span2)}>
                        <input
                          type="checkbox"
                          checked={Boolean(field.config.multiple)}
                          onChange={(e) => patchConfig(index, { multiple: e.target.checked })}
                        />
                        {t(
                          field.type === 'image' ? 'schema.image.multiple' : 'schema.file.multiple',
                        )}
                      </label>
                      <div className={clsx(styles.field, styles.span2)}>
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
                          placeholder={
                            field.type === 'image' ? 'jpg, png, webp, avif' : 'pdf, docx, zip'
                          }
                        />
                        <p className={styles.hint}>
                          {t(
                            field.type === 'image'
                              ? 'schema.image.formatsHint'
                              : 'schema.file.formatsHint',
                          )}
                        </p>
                      </div>
                      {field.type === 'image' ? (
                        <div className={clsx(styles.field, styles.span2)}>
                          <Label>{t('schema.image.encodeFormat')}</Label>
                          <Select
                            value={
                              typeof field.config.encodeFormat === 'string' &&
                              field.config.encodeFormat !== ''
                                ? field.config.encodeFormat
                                : ''
                            }
                            onChange={(e) =>
                              patchConfig(index, {
                                encodeFormat: e.target.value === '' ? null : e.target.value,
                              })
                            }
                          >
                            <option value="">{t('schema.image.encodeFormatKeep')}</option>
                            {encodeCaps.data?.webp !== false ? (
                              <option value="webp">WebP</option>
                            ) : null}
                            {encodeCaps.data?.avif || field.config.encodeFormat === 'avif' ? (
                              <option value="avif" disabled={!encodeCaps.data?.avif}>
                                {encodeCaps.data?.avif ? 'AVIF' : 'AVIF (unavailable)'}
                              </option>
                            ) : null}
                            <option value="jpeg">JPEG</option>
                            <option value="png">PNG</option>
                          </Select>
                          <p className={styles.hint}>
                            {encodeCaps.data?.avif
                              ? t('schema.image.encodeFormatHint')
                              : t('schema.image.encodeFormatHintNoAvif')}
                          </p>
                        </div>
                      ) : null}
                    </>
                  ) : null}
                  {field.type === 'image' ? (
                    <div className={clsx(styles.sizesSection, styles.span2)}>
                      <div className={styles.sizesHeader}>
                        <Label>{t('schema.image.sizes')}</Label>
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          onClick={() => {
                            const existing = field.config.sizes
                            const sizes: ImageSizeConfig[] = Array.isArray(existing)
                              ? (existing as ImageSizeConfig[]).slice()
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
                          <Plus className={styles.iconSmGap} />
                          {t('schema.image.addSize')}
                        </Button>
                      </div>
                      {(Array.isArray(field.config.sizes)
                        ? (field.config.sizes as ImageSizeConfig[])
                        : []
                      ).map((size, sizeIndex) => (
                        <div key={`size-${sizeIndex}`} className={styles.sizeRow}>
                          <div className={styles.fieldTight}>
                            <Label className={styles.labelXs}>{t('schema.image.prefix')}</Label>
                            <Input
                              className={styles.inputPrefix}
                              value={size.prefix}
                              maxLength={32}
                              placeholder="crop"
                              onChange={(e) => {
                                const sizes = [...(field.config.sizes as ImageSizeConfig[])]
                                const current = sizes[sizeIndex]
                                if (current === undefined) return
                                sizes[sizeIndex] = {
                                  ...current,
                                  prefix: slugifyIdentifier(e.target.value, 32),
                                }
                                patchConfig(index, { sizes })
                              }}
                            />
                          </div>
                          <div className={styles.fieldTight}>
                            <Label className={styles.labelXs}>{t('schema.image.width')}</Label>
                            <Input
                              className={styles.inputDim}
                              type="number"
                              min={1}
                              value={size.width}
                              onChange={(e) => {
                                const sizes = [...(field.config.sizes as ImageSizeConfig[])]
                                const current = sizes[sizeIndex]
                                if (current === undefined) return
                                sizes[sizeIndex] = {
                                  ...current,
                                  width: Number(e.target.value) || 1,
                                }
                                patchConfig(index, { sizes })
                              }}
                            />
                          </div>
                          <div className={styles.fieldTight}>
                            <Label className={styles.labelXs}>{t('schema.image.height')}</Label>
                            <Input
                              className={styles.inputDim}
                              type="number"
                              min={1}
                              value={size.height}
                              onChange={(e) => {
                                const sizes = [...(field.config.sizes as ImageSizeConfig[])]
                                const current = sizes[sizeIndex]
                                if (current === undefined) return
                                sizes[sizeIndex] = {
                                  ...current,
                                  height: Number(e.target.value) || 1,
                                }
                                patchConfig(index, { sizes })
                              }}
                            />
                          </div>
                          <div className={styles.fieldTight}>
                            <Label className={styles.labelXs}>{t('schema.image.mode')}</Label>
                            <div className={clsx(controlFieldClass, styles.modeControl)}>
                              <span
                                className={clsx(
                                  styles.modeOption,
                                  size.mode === 'resize' ? styles.modeMuted : styles.modeActive,
                                )}
                              >
                                <Crop className={styles.iconSm} />
                                {t('schema.image.modeCrop')}
                              </span>
                              <Switch
                                aria-label={t('schema.image.mode')}
                                checked={size.mode === 'resize'}
                                onCheckedChange={(checked) => {
                                  const sizes = [...(field.config.sizes as ImageSizeConfig[])]
                                  const current = sizes[sizeIndex]
                                  if (current === undefined) return
                                  sizes[sizeIndex] = {
                                    ...current,
                                    mode: checked ? 'resize' : 'crop',
                                  }
                                  patchConfig(index, { sizes })
                                }}
                              />
                              <span
                                className={clsx(
                                  styles.modeOption,
                                  size.mode === 'resize' ? styles.modeActive : styles.modeMuted,
                                )}
                              >
                                <Images className={styles.iconSm} />
                                {t('schema.image.modeResize')}
                              </span>
                            </div>
                          </div>
                          <div className={styles.fieldTight}>
                            <Label className={styles.labelXs}>{t('schema.image.position')}</Label>
                            <AnchorPicker
                              value={size.position || 'c'}
                              title={t('schema.image.position')}
                              onChange={(position) => {
                                const sizes = [...(field.config.sizes as ImageSizeConfig[])]
                                const current = sizes[sizeIndex]
                                if (current === undefined) return
                                sizes[sizeIndex] = { ...current, position }
                                patchConfig(index, { sizes })
                              }}
                            />
                          </div>
                          <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            className={styles.destructiveText}
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
                      <div className={styles.field}>
                        <Label>{t('schema.relation.relatedSlug')}</Label>
                        <Input
                          value={configString(field.config.relatedSlug)}
                          onChange={(e) => patchConfig(index, { relatedSlug: e.target.value })}
                          placeholder="posts"
                        />
                      </div>
                      <div className={styles.field}>
                        <Label>{t('schema.relation.cardinality')}</Label>
                        <Select
                          value={
                            typeof field.config.cardinality === 'string'
                              ? field.config.cardinality
                              : 'manyToOne'
                          }
                          onChange={(e) => {
                            const cardinality = e.target.value
                            updateAt(index, {
                              config: { ...field.config, cardinality },
                              writable: cardinality === 'oneToMany' ? false : true,
                            })
                          }}
                        >
                          <option value="manyToOne">{t('schema.relation.manyToOne')}</option>
                          <option value="oneToOne">{t('schema.relation.oneToOne')}</option>
                          <option value="manyToMany">{t('schema.relation.manyToMany')}</option>
                          <option value="oneToMany">{t('schema.relation.oneToMany')}</option>
                        </Select>
                      </div>
                      <div className={styles.field}>
                        <Label>{t('schema.relation.labelField')}</Label>
                        <Input
                          value={configString(field.config.labelField, 'id')}
                          onChange={(e) => patchConfig(index, { labelField: e.target.value })}
                          placeholder="title"
                        />
                      </div>
                      {field.config.cardinality === 'oneToMany' ? (
                        <div className={styles.field}>
                          <Label>{t('schema.relation.foreignKey')}</Label>
                          <Input
                            value={configString(field.config.foreignKey)}
                            onChange={(e) => patchConfig(index, { foreignKey: e.target.value })}
                            placeholder="post_id"
                          />
                        </div>
                      ) : null}
                    </>
                  ) : null}
                  {!FIELD_TYPES.includes(field.type as (typeof FIELD_TYPES)[number]) ? (
                    <div className={styles.field}>
                      <Label>{t('schema.pluginConfig')}</Label>
                      <textarea
                        className={controlFieldClass}
                        rows={6}
                        value={JSON.stringify(field.config ?? {}, null, 2)}
                        onChange={(e) => {
                          try {
                            const parsed = JSON.parse(e.target.value) as unknown
                            if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                              updateAt(index, { config: parsed as Record<string, unknown> })
                            }
                          } catch {
                            /* keep typing */
                          }
                        }}
                      />
                      <p className={styles.hint}>{t('schema.pluginConfigHint')}</p>
                    </div>
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
