import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { TableSkeleton } from '@/components/skeletons'
import { EmptyState } from '@/components/EmptyState'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import type { SchemaField } from '@/types/field'
import type { Resource } from '@/types/resource'
import {
  emptyJoin,
  type ResourceApiJoin,
  type ResourceCustomApi,
  type ResourceCustomApiInput,
} from '@/types/resourceApi'

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
    fields: null,
    joins: [],
    settings: {
      pagination: true,
      search: true,
      sorting: true,
      filtering: true,
      public: { read: null },
    },
  }
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
  const [message, setMessage] = useState<string | null>(null)
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
      setMessage(t('resources.customApis.saved'))
      setEditingId(null)
      void queryClient.invalidateQueries({ queryKey: ['resource-apis', resource.id] })
    },
    onError: (err) => setMessage(err instanceof Error ? err.message : t('common.saveFailed')),
  })

  const remove = useMutation({
    mutationFn: (id: number) =>
      api<void>(`/admin/api/resources/${resource.id}/apis/${id}`, { method: 'DELETE' }),
    onSuccess: () => {
      setMessage(t('resources.customApis.deleted'))
      if (editingId !== 'new' && editingId !== null) setEditingId(null)
      void queryClient.invalidateQueries({ queryKey: ['resource-apis', resource.id] })
    },
    onError: (err) => setMessage(err instanceof Error ? err.message : t('common.saveFailed')),
  })

  const selectable = useMemo(() => projectableFields(fields), [fields])
  const apis = apisQuery.data ?? []

  function startCreate() {
    setEditingId('new')
    setDraft(emptyDraft())
    setAllFields(true)
    setMessage(null)
  }

  function startEdit(apiItem: ResourceCustomApi) {
    setEditingId(apiItem.id)
    setDraft({
      slug: apiItem.slug,
      label: apiItem.label,
      enabled: apiItem.enabled,
      fields: apiItem.fields,
      joins: apiItem.joins.length > 0 ? apiItem.joins : [],
      settings: apiItem.settings,
    })
    setAllFields(apiItem.fields === null)
    setMessage(null)
  }

  function patchJoin(index: number, partial: Partial<ResourceApiJoin>) {
    setDraft((prev) => ({
      ...prev,
      joins: prev.joins.map((join, i) => (i === index ? { ...join, ...partial } : join)),
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
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle>{t('resources.customApis.title')}</CardTitle>
          <CardDescription>{t('resources.customApis.hint')}</CardDescription>
        </div>
        <Button size="sm" onClick={startCreate}>
          <Plus className="h-4 w-4" />
          {t('resources.customApis.create')}
        </Button>
      </CardHeader>
      <CardContent className="space-y-4">
        {apisQuery.isLoading ? (
          <TableSkeleton columns={3} rows={4} />
        ) : apis.length === 0 && editingId === null ? (
          <EmptyState title={t('resources.customApis.empty')} />
        ) : (
          <div className="space-y-2">
            {apis.map((apiItem) => (
              <div
                key={apiItem.id}
                className="flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2"
              >
                <div className="min-w-0">
                  <p className="font-medium">{apiItem.label}</p>
                  <p className="font-mono text-xs text-muted-foreground">{apiItem.path}</p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  <span className="text-xs text-muted-foreground">
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
                    <Trash2 className="h-4 w-4 text-destructive" />
                  </Button>
                </div>
              </div>
            ))}
          </div>
        )}

        {editingId !== null ? (
          <div className="space-y-4 rounded-md border p-4">
            <div className="grid gap-3 md:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="custom-api-label">{t('common.label')}</Label>
                <Input
                  id="custom-api-label"
                  value={draft.label}
                  onChange={(e) => setDraft((prev) => ({ ...prev, label: e.target.value }))}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="custom-api-slug">{t('common.slug')}</Label>
                <Input
                  id="custom-api-slug"
                  className="font-mono"
                  value={draft.slug}
                  onChange={(e) =>
                    setDraft((prev) => ({
                      ...prev,
                      slug: e.target.value.toLowerCase().replace(/[^a-z0-9_-]/g, ''),
                    }))
                  }
                  placeholder="with-category"
                />
                <p className="font-mono text-xs text-muted-foreground">
                  {resource.endpoint}/{draft.slug || '…'}
                </p>
              </div>
            </div>

            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={draft.enabled}
                onChange={(e) => setDraft((prev) => ({ ...prev, enabled: e.target.checked }))}
              />
              {t('resources.customApis.enabled')}
            </label>

            <div className="space-y-2">
              <p className="text-sm font-medium">{t('resources.customApis.fields')}</p>
              <label className="flex items-center gap-2 text-sm">
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
                <div className="grid gap-2 sm:grid-cols-2 md:grid-cols-3">
                  {selectable.map((field) => (
                    <label key={field.name} className="flex items-center gap-2 text-sm">
                      <input
                        type="checkbox"
                        checked={(draft.fields ?? []).includes(field.name)}
                        onChange={() => toggleField(field.name)}
                      />
                      <span className="font-mono text-xs">{field.name}</span>
                    </label>
                  ))}
                </div>
              ) : null}
            </div>

            <div className="space-y-3">
              <div className="flex items-center justify-between gap-2">
                <p className="text-sm font-medium">{t('resources.customApis.joins')}</p>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() =>
                    setDraft((prev) => ({ ...prev, joins: [...prev.joins, emptyJoin()] }))
                  }
                >
                  <Plus className="h-4 w-4" />
                  {t('resources.customApis.addJoin')}
                </Button>
              </div>
              {draft.joins.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                  {t('resources.customApis.joinsEmpty')}
                </p>
              ) : (
                draft.joins.map((join, index) => {
                  const relatedFields = relatedFieldsQuery.data?.[join.relatedSlug] ?? []
                  return (
                    <div key={index} className="space-y-3 rounded-md border p-3">
                      <div className="flex items-start justify-between gap-2">
                        <p className="text-sm font-medium">
                          {t('resources.customApis.joinItem', { n: index + 1 })}
                        </p>
                        <Button
                          type="button"
                          size="icon"
                          variant="ghost"
                          className="h-8 w-8 shrink-0"
                          aria-label={t('resources.customApis.removeJoin')}
                          title={t('resources.customApis.removeJoin')}
                          onClick={() =>
                            setDraft((prev) => ({
                              ...prev,
                              joins: prev.joins.filter((_, i) => i !== index),
                            }))
                          }
                        >
                          <Trash2 className="h-4 w-4 text-destructive" />
                        </Button>
                      </div>
                      <div className="grid gap-3 md:grid-cols-2">
                        <div className="space-y-2">
                          <Label>{t('resources.customApis.joinAs')}</Label>
                          <Input
                            className="font-mono"
                            value={join.as}
                            onChange={(e) =>
                              patchJoin(index, {
                                as: e.target.value.replace(/[^a-zA-Z0-9_]/g, ''),
                              })
                            }
                            placeholder="category"
                          />
                        </div>
                        <div className="space-y-2">
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
                        <div className="space-y-2">
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
                        <div className="space-y-2">
                          <Label>{t('resources.customApis.foreignField')}</Label>
                          <Input
                            className="font-mono"
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
                        <div className="space-y-2">
                          <p className="text-xs text-muted-foreground">
                            {t('resources.customApis.joinFieldsHint')}
                          </p>
                          <div className="grid gap-2 sm:grid-cols-2 md:grid-cols-3">
                            {projectableFields(relatedFields).map((field) => (
                              <label key={field.name} className="flex items-center gap-2 text-sm">
                                <input
                                  type="checkbox"
                                  checked={(join.fields ?? []).includes(field.name)}
                                  onChange={() => toggleJoinField(index, field.name)}
                                />
                                <span className="font-mono text-xs">{field.name}</span>
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

            <div className="flex flex-wrap items-center gap-2">
              <Button disabled={save.isPending} onClick={() => save.mutate()}>
                {save.isPending ? t('common.saving') : t('common.save')}
              </Button>
              <Button variant="outline" onClick={() => setEditingId(null)}>
                {t('common.cancel')}
              </Button>
            </div>
          </div>
        ) : null}

        {message ? <p className="text-sm text-muted-foreground">{message}</p> : null}
      </CardContent>
    </Card>
  )
}
