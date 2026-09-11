import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { useMemo, useState } from 'react'
import { EmptyState } from '@/components/EmptyState'
import { TableSkeleton } from '@/components/skeletons'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Form } from '@/components/ui/form'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import { showSuccess } from '@/lib/toast'
import {
  emptyJoin,
  isWriteMethod,
  methodAction,
  RESOURCE_API_METHODS,
  type ResourceApiJoin,
  type ResourceApiMethod,
  type ResourceApiSettings,
  type ResourceCustomApi,
  type ResourceCustomApiInput,
} from '@/types/resourceApi'
import styles from './ResourceCustomApisPanel.module.css'
import type { SchemaField } from '@/types/field'
import type { Resource } from '@/types/resource'

interface ResourceCustomApisPanelProps {
  resource: Resource
  fields: SchemaField[]
  onSelectPath?: (path: string) => void
}

function projectableFields(fields: SchemaField[]): SchemaField[] {
  return fields.filter((field) => {
    if (field.type === 'relation' && field.config.cardinality === 'oneToMany') return false
    return field.readable && !field.hidden
  })
}

function emptyDraft(): ResourceCustomApiInput {
  return {
    slug: '',
    label: '',
    enabled: true,
    methods: ['GET'],
    fields: null,
    joins: [],
    settings: {
      pagination: true,
      search: true,
      sorting: true,
      filtering: true,
      public: { read: null, create: null, update: null, delete: null },
    },
  }
}

/** POST requires every required field to be writable through the projection. */
function missingRequiredFields(draft: ResourceCustomApiInput, fields: SchemaField[]): string[] {
  if (!draft.methods.includes('POST') || draft.fields === null) return []
  const selected = draft.fields
  return fields
    .filter((field) => {
      if (!field.required || !field.writable) return false
      if (field.type === 'slug' && field.config.associatedWith) return false
      return !selected.includes(field.name)
    })
    .map((field) => field.name)
}

export function ResourceCustomApisPanel({
  resource,
  fields,
  onSelectPath,
}: ResourceCustomApisPanelProps) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [editingId, setEditingId] = useState<number | 'new' | null>(null)
  const [draft, setDraft] = useState<ResourceCustomApiInput>(() => emptyDraft())
  const [allFields, setAllFields] = useState(true)

  const apisQuery = useQuery({
    queryKey: ['resource-apis', resource.id],
    queryFn: () => api<ResourceCustomApi[]>(`/admin/api/resources/${resource.id}/apis`),
  })

  const resourcesQuery = useQuery({
    queryKey: ['resources'],
    queryFn: () => api<Resource[]>('/admin/api/resources'),
  })

  const publishedResources = useMemo(
    () => (resourcesQuery.data ?? []).filter((r) => r.status === 'published'),
    [resourcesQuery.data],
  )

  const relatedFieldsQuery = useQuery({
    queryKey: [
      'resource-fields-by-slug',
      draft.joins.map((j) => j.relatedSlug).join(','),
      publishedResources.map((r) => r.id).join(','),
    ],
    enabled: publishedResources.length > 0 && draft.joins.some((j) => j.relatedSlug !== ''),
    queryFn: async () => {
      const map: Record<string, SchemaField[]> = {}
      await Promise.all(
        draft.joins
          .map((j) => j.relatedSlug)
          .filter(Boolean)
          .map(async (slug) => {
            const related = publishedResources.find((r) => r.slug === slug)
            if (!related) return
            map[slug] = await api<SchemaField[]>(`/admin/api/resources/${related.id}/fields`)
          }),
      )
      return map
    },
  })

  const save = useMutation({
    mutationFn: async () => {
      const payload: ResourceCustomApiInput = {
        ...draft,
        fields: allFields ? null : (draft.fields ?? []),
        methods: RESOURCE_API_METHODS.filter((method) => draft.methods.includes(method)),
        joins: draft.joins.map((join) => ({
          ...join,
          fields: join.fields && join.fields.length > 0 ? join.fields : null,
        })),
      }
      if (editingId === 'new') {
        return api<ResourceCustomApi>(`/admin/api/resources/${resource.id}/apis`, {
          method: 'POST',
          body: JSON.stringify(payload),
        })
      }
      return api<ResourceCustomApi>(`/admin/api/resources/${resource.id}/apis/${editingId}`, {
        method: 'PATCH',
        body: JSON.stringify(payload),
      })
    },
    onSuccess: () => {
      showSuccess(t('resources.customApis.saved'))
      setEditingId(null)
      void queryClient.invalidateQueries({ queryKey: ['resource-apis', resource.id] })
    },
  })

  const remove = useMutation({
    mutationFn: (id: number) =>
      api<void>(`/admin/api/resources/${resource.id}/apis/${id}`, { method: 'DELETE' }),
    onSuccess: () => {
      showSuccess(t('resources.customApis.deleted'))
      if (editingId !== 'new' && editingId !== null) setEditingId(null)
      void queryClient.invalidateQueries({ queryKey: ['resource-apis', resource.id] })
    },
  })

  const selectable = useMemo(() => projectableFields(fields), [fields])
  const apis = apisQuery.data ?? []
  const writesBlockedByJoins = draft.joins.length > 0
  const missingRequired = useMemo(
    () => (allFields ? [] : missingRequiredFields(draft, fields)),
    [allFields, draft, fields],
  )
  const publicWriteEnabled = RESOURCE_API_METHODS.filter(isWriteMethod).some(
    (method) =>
      draft.methods.includes(method) && draft.settings.public[methodAction(method)] === true,
  )
  const saveBlocked =
    (writesBlockedByJoins && draft.methods.some(isWriteMethod)) || missingRequired.length > 0

  function startCreate() {
    setEditingId('new')
    setDraft(emptyDraft())
    setAllFields(true)
  }

  function startEdit(apiItem: ResourceCustomApi) {
    setEditingId(apiItem.id)
    setDraft({
      slug: apiItem.slug,
      label: apiItem.label,
      enabled: apiItem.enabled,
      methods: apiItem.methods,
      fields: apiItem.fields,
      joins: apiItem.joins.length > 0 ? apiItem.joins : [],
      settings: apiItem.settings,
    })
    setAllFields(apiItem.fields === null)
  }

  function patchJoin(index: number, partial: Partial<ResourceApiJoin>) {
    setDraft((prev) => ({
      ...prev,
      joins: prev.joins.map((join, i) => (i === index ? { ...join, ...partial } : join)),
    }))
  }

  function toggleMethod(method: ResourceApiMethod) {
    setDraft((prev) => ({
      ...prev,
      methods: prev.methods.includes(method)
        ? prev.methods.filter((m) => m !== method)
        : [...prev.methods, method],
    }))
  }

  function patchPublic(action: keyof ResourceApiSettings['public'], value: boolean | null) {
    setDraft((prev) => ({
      ...prev,
      settings: { ...prev.settings, public: { ...prev.settings.public, [action]: value } },
    }))
  }

  function toggleField(name: string) {
    setDraft((prev) => {
      const current = prev.fields ?? []
      const next = current.includes(name) ? current.filter((f) => f !== name) : [...current, name]
      return { ...prev, fields: next }
    })
  }

  function toggleJoinField(index: number, name: string) {
    setDraft((prev) => ({
      ...prev,
      joins: prev.joins.map((join, i) => {
        if (i !== index) return join
        const current = join.fields ?? []
        const next = current.includes(name) ? current.filter((f) => f !== name) : [...current, name]
        return { ...join, fields: next }
      }),
    }))
  }

  return (
    <Card>
      <CardHeader className={styles.headerRow}>
        <div>
          <CardTitle>{t('resources.customApis.title')}</CardTitle>
          <CardDescription>{t('resources.customApis.hint')}</CardDescription>
        </div>
        <Button size="sm" onClick={startCreate}>
          <Plus className={styles.icon} />
          {t('resources.customApis.create')}
        </Button>
      </CardHeader>
      <CardContent className={styles.stack}>
        {apisQuery.isLoading ? (
          <TableSkeleton columns={3} rows={4} />
        ) : apis.length === 0 && editingId === null ? (
          <EmptyState title={t('resources.customApis.empty')} />
        ) : (
          <div className={styles.list}>
            {apis.map((apiItem) => (
              <div key={apiItem.id} className={styles.apiRow}>
                <div className={styles.apiMeta}>
                  <p className={styles.apiLabel}>{apiItem.label}</p>
                  <p className={styles.apiPath}>
                    {apiItem.methods.join(' ')} {apiItem.path}
                  </p>
                </div>
                <div className={styles.apiActions}>
                  <span className={styles.statusHint}>
                    {apiItem.enabled ? t('common.enabled') : t('common.disabled')}
                  </span>
                  <Button size="sm" variant="outline" onClick={() => onSelectPath?.(apiItem.path)}>
                    {t('resources.customApis.try')}
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => startEdit(apiItem)}>
                    {t('common.edit')}
                  </Button>
                  <Button
                    size="icon"
                    variant="ghost"
                    aria-label={t('common.delete')}
                    onClick={() => {
                      if (
                        confirm(t('resources.customApis.deleteConfirm', { label: apiItem.label }))
                      ) {
                        remove.mutate(apiItem.id)
                      }
                    }}
                  >
                    <Trash2 className={styles.iconDestructive} />
                  </Button>
                </div>
              </div>
            ))}
          </div>
        )}

        {editingId !== null ? (
          <Form className={styles.editor} onSubmit={() => save.mutate()}>
            <div className={styles.grid2}>
              <div className={styles.field}>
                <Label htmlFor="custom-api-label">{t('common.label')}</Label>
                <Input
                  id="custom-api-label"
                  value={draft.label}
                  onChange={(e) => setDraft((prev) => ({ ...prev, label: e.target.value }))}
                />
              </div>
              <div className={styles.field}>
                <Label htmlFor="custom-api-slug">{t('common.slug')}</Label>
                <Input
                  id="custom-api-slug"
                  className={styles.mono}
                  value={draft.slug}
                  onChange={(e) =>
                    setDraft((prev) => ({
                      ...prev,
                      slug: e.target.value.toLowerCase().replace(/[^a-z0-9_-]/g, ''),
                    }))
                  }
                  placeholder="with-category"
                />
                <p className={styles.monoHint}>
                  {resource.endpoint}/{draft.slug || '…'}
                </p>
              </div>
            </div>

            <label className={styles.checkLabel}>
              <input
                type="checkbox"
                checked={draft.enabled}
                onChange={(e) => setDraft((prev) => ({ ...prev, enabled: e.target.checked }))}
              />
              {t('resources.customApis.enabled')}
            </label>

            <div className={styles.field}>
              <p className={styles.sectionTitle}>{t('resources.customApis.methods')}</p>
              <p className={styles.hint}>{t('resources.customApis.methodsHint')}</p>
              <div className={styles.methodRow}>
                {RESOURCE_API_METHODS.map((method) => (
                  <label key={method} className={styles.checkLabel}>
                    <input
                      type="checkbox"
                      checked={draft.methods.includes(method)}
                      disabled={writesBlockedByJoins && isWriteMethod(method)}
                      onChange={() => toggleMethod(method)}
                    />
                    <span className={styles.monoXs}>{method}</span>
                  </label>
                ))}
              </div>
              {writesBlockedByJoins ? (
                <p className={styles.hint}>{t('resources.customApis.methodsJoinsBlocked')}</p>
              ) : null}
            </div>

            <div className={styles.field}>
              <p className={styles.sectionTitle}>{t('resources.customApis.publicAccess')}</p>
              <p className={styles.hint}>{t('resources.customApis.publicAccessHint')}</p>
              <div className={styles.grid2}>
                {RESOURCE_API_METHODS.filter((method) => draft.methods.includes(method)).map(
                  (method) => {
                    const action = methodAction(method)
                    const value = draft.settings.public[action]
                    return (
                      <div key={action} className={styles.fieldTight}>
                        <Label htmlFor={`custom-api-public-${action}`}>
                          {t(`resources.customApis.public.${action}`)}
                        </Label>
                        <Select
                          id={`custom-api-public-${action}`}
                          value={value === null ? 'inherit' : value ? 'yes' : 'no'}
                          onChange={(e) =>
                            patchPublic(
                              action,
                              e.target.value === 'inherit' ? null : e.target.value === 'yes',
                            )
                          }
                        >
                          <option value="inherit">{t('resources.customApis.inherit')}</option>
                          <option value="yes">{t('common.yes')}</option>
                          <option value="no">{t('common.no')}</option>
                        </Select>
                      </div>
                    )
                  },
                )}
              </div>
              {publicWriteEnabled ? (
                <p className={styles.warning}>{t('resources.customApis.publicWriteWarning')}</p>
              ) : null}
              <p className={styles.hint}>{t('resources.customApis.grantsHint')}</p>
            </div>

            <div className={styles.field}>
              <p className={styles.sectionTitle}>{t('resources.customApis.fields')}</p>
              <label className={styles.checkLabel}>
                <input
                  type="checkbox"
                  checked={allFields}
                  onChange={(e) => {
                    setAllFields(e.target.checked)
                    if (e.target.checked) {
                      setDraft((prev) => ({ ...prev, fields: null }))
                    } else {
                      setDraft((prev) => ({ ...prev, fields: [] }))
                    }
                  }}
                />
                {t('resources.customApis.allFields')}
              </label>
              {!allFields ? (
                <div className={styles.fieldsGrid}>
                  {selectable.map((field) => (
                    <label key={field.name} className={styles.checkLabel}>
                      <input
                        type="checkbox"
                        checked={(draft.fields ?? []).includes(field.name)}
                        onChange={() => toggleField(field.name)}
                      />
                      <span className={styles.monoXs}>{field.name}</span>
                    </label>
                  ))}
                </div>
              ) : null}
              {missingRequired.length > 0 ? (
                <p className={styles.error}>
                  {t('resources.customApis.missingRequired', {
                    fields: missingRequired.join(', '),
                  })}
                </p>
              ) : null}
            </div>

            <div className={styles.joinsSection}>
              <div className={styles.joinsHeader}>
                <p className={styles.sectionTitle}>{t('resources.customApis.joins')}</p>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() =>
                    setDraft((prev) => ({ ...prev, joins: [...prev.joins, emptyJoin()] }))
                  }
                >
                  <Plus className={styles.icon} />
                  {t('resources.customApis.addJoin')}
                </Button>
              </div>
              {draft.joins.length === 0 ? (
                <p className={styles.muted}>{t('resources.customApis.joinsEmpty')}</p>
              ) : (
                draft.joins.map((join, index) => {
                  const relatedFields = relatedFieldsQuery.data?.[join.relatedSlug] ?? []
                  return (
                    <div key={index} className={styles.joinCard}>
                      <div className={styles.joinHeader}>
                        <p className={styles.joinTitle}>
                          {t('resources.customApis.joinItem', { n: index + 1 })}
                        </p>
                        <Button
                          type="button"
                          size="icon"
                          variant="ghost"
                          className={styles.removeBtn}
                          aria-label={t('resources.customApis.removeJoin')}
                          title={t('resources.customApis.removeJoin')}
                          onClick={() =>
                            setDraft((prev) => ({
                              ...prev,
                              joins: prev.joins.filter((_, i) => i !== index),
                            }))
                          }
                        >
                          <Trash2 className={styles.iconDestructive} />
                        </Button>
                      </div>
                      <div className={styles.grid2}>
                        <div className={styles.field}>
                          <Label>{t('resources.customApis.joinAs')}</Label>
                          <Input
                            className={styles.mono}
                            value={join.as}
                            onChange={(e) =>
                              patchJoin(index, {
                                as: e.target.value.replace(/[^a-zA-Z0-9_]/g, ''),
                              })
                            }
                            placeholder="category"
                          />
                        </div>
                        <div className={styles.field}>
                          <Label>{t('resources.customApis.relatedSlug')}</Label>
                          <Select
                            value={join.relatedSlug}
                            onChange={(e) =>
                              patchJoin(index, { relatedSlug: e.target.value, fields: null })
                            }
                          >
                            <option value="">{t('resources.customApis.selectResource')}</option>
                            {publishedResources.map((r) => (
                              <option key={r.id} value={r.slug}>
                                {r.label} ({r.slug})
                              </option>
                            ))}
                          </Select>
                        </div>
                        <div className={styles.field}>
                          <Label>{t('resources.customApis.localField')}</Label>
                          <Select
                            value={join.localField}
                            onChange={(e) => patchJoin(index, { localField: e.target.value })}
                          >
                            <option value="">{t('resources.customApis.selectField')}</option>
                            {selectable.map((field) => (
                              <option key={field.name} value={field.name}>
                                {field.name}
                              </option>
                            ))}
                          </Select>
                        </div>
                        <div className={styles.field}>
                          <Label>{t('resources.customApis.foreignField')}</Label>
                          <Input
                            className={styles.mono}
                            value={join.foreignField}
                            onChange={(e) =>
                              patchJoin(index, {
                                foreignField: e.target.value.replace(/[^a-zA-Z0-9_]/g, '') || 'id',
                              })
                            }
                            placeholder="id"
                          />
                        </div>
                      </div>
                      {join.relatedSlug && relatedFields.length > 0 ? (
                        <div className={styles.field}>
                          <p className={styles.hint}>{t('resources.customApis.joinFieldsHint')}</p>
                          <div className={styles.fieldsGrid}>
                            {projectableFields(relatedFields).map((field) => (
                              <label key={field.name} className={styles.checkLabel}>
                                <input
                                  type="checkbox"
                                  checked={(join.fields ?? []).includes(field.name)}
                                  onChange={() => toggleJoinField(index, field.name)}
                                />
                                <span className={styles.monoXs}>{field.name}</span>
                              </label>
                            ))}
                          </div>
                        </div>
                      ) : null}
                    </div>
                  )
                })
              )}
            </div>

            <div className={styles.editorActions}>
              <Button type="submit" disabled={save.isPending || saveBlocked}>
                {save.isPending ? t('common.saving') : t('common.save')}
              </Button>
              <Button variant="outline" onClick={() => setEditingId(null)}>
                {t('common.cancel')}
              </Button>
            </div>
          </Form>
        ) : null}
      </CardContent>
    </Card>
  )
}
