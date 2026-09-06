import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Badge } from '@/components/ui/badge'
import { Button, buttonVariants } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { ResourceApiPlayground } from '@/features/resources/ResourceApiPlayground'
import { ResourceCustomApisPanel } from '@/features/resources/ResourceCustomApisPanel'
import { ResourceEntriesPanel } from '@/features/resources/ResourceEntriesPanel'
import { ResourceExportPanel } from '@/features/resources/ResourceExportPanel'
import { ResourceFetchExample } from '@/features/resources/ResourceFetchExample'
import { ResourceSettingsPanel } from '@/features/resources/ResourceSettingsPanel'
import { SchemaBuilder } from '@/features/schema-builder/SchemaBuilder'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import { cn } from '@/lib/utils'
import type { SchemaField } from '@/types/field'
import type { Resource } from '@/types/resource'

const TABS = ['overview', 'schema', 'data', 'settings', 'api', 'export'] as const
type Tab = (typeof TABS)[number]

const tabKeys = {
  overview: 'resources.tab.overview',
  schema: 'resources.tab.schema',
  data: 'resources.tab.data',
  settings: 'resources.tab.settings',
  api: 'resources.tab.api',
  export: 'resources.tab.export',
} as const

function isTab(value: string | undefined): value is Tab {
  return TABS.includes(value as Tab)
}

export function ResourceDetailPage() {
  const { t } = useI18n()
  const { id, tab: tabParam } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const resourceId = Number(id)
  const tab: Tab = isTab(tabParam) ? tabParam : 'overview'
  const [draftSchema, setDraftSchema] = useState<SchemaField[] | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [playgroundPath, setPlaygroundPath] = useState<string | null>(null)

  useEffect(() => {
    if (!Number.isFinite(resourceId) || resourceId <= 0) return
    if (!isTab(tabParam)) {
      navigate(`/resources/${resourceId}/overview`, { replace: true })
    }
  }, [navigate, resourceId, tabParam])

  const query = useQuery({
    queryKey: ['resource', resourceId],
    queryFn: () => api<Resource>(`/admin/api/resources/${resourceId}`),
    enabled: Number.isFinite(resourceId) && resourceId > 0,
  })

  const fieldsQuery = useQuery({
    queryKey: ['resource-fields', resourceId],
    queryFn: () => api<SchemaField[]>(`/admin/api/resources/${resourceId}/fields`),
    enabled: Number.isFinite(resourceId) && resourceId > 0,
  })

  const schema = draftSchema ?? fieldsQuery.data ?? []
  const schemaDirty = draftSchema !== null

  const publish = useMutation({
    mutationFn: () =>
      api<Resource>(`/admin/api/resources/${resourceId}/publish`, { method: 'POST', body: '{}' }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['resource', resourceId] })
      void queryClient.invalidateQueries({ queryKey: ['resources'] })
    },
  })

  const remove = useMutation({
    mutationFn: () => api<void>(`/admin/api/resources/${resourceId}`, { method: 'DELETE' }),
    onSuccess: () => navigate('/resources'),
  })

  const saveSchema = useMutation({
    mutationFn: () =>
      api<SchemaField[]>(`/admin/api/resources/${resourceId}/fields`, {
        method: 'PUT',
        body: JSON.stringify({ fields: schema }),
      }),
    onSuccess: (data) => {
      setDraftSchema(null)
      setMessage(t('resources.schemaSaved'))
      queryClient.setQueryData(['resource-fields', resourceId], data)
    },
    onError: (err) => setMessage(err instanceof Error ? err.message : t('common.saveFailed')),
  })

  const resource = query.data

  if (query.isLoading) {
    return <p className="text-sm text-muted-foreground">{t('common.loading')}</p>
  }

  if (!resource) {
    return (
      <div className="space-y-4">
        <p className="text-sm text-destructive">{t('resources.notFound')}</p>
        <Button variant="outline" onClick={() => navigate('/resources')}>
          {t('common.back')}
        </Button>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p className="text-sm text-muted-foreground">
            <Link to="/resources" className="hover:underline">
              {t('nav.resources')}
            </Link>{' '}
            / {resource.label}
          </p>
          <h1 className="mt-1 text-2xl font-semibold">{resource.label}</h1>
          <div className="mt-2 flex items-center gap-2">
            <Badge>{resource.status}</Badge>
            <span className="font-mono text-xs text-muted-foreground">{resource.endpoint}</span>
          </div>
        </div>
        <div className="flex gap-2">
          {resource.status !== 'published' ? (
            <Button disabled={publish.isPending} onClick={() => publish.mutate()}>
              {t('resources.publish')}
            </Button>
          ) : null}
          {!resource.isSystem ? (
            <Button
              variant="destructive"
              disabled={remove.isPending}
              onClick={() => {
                if (confirm(t('resources.deleteConfirm', { label: resource.label }))) {
                  remove.mutate()
                }
              }}
            >
              {t('common.delete')}
            </Button>
          ) : null}
        </div>
      </div>

      <div className="flex flex-wrap gap-2 border-b pb-2">
        {TABS.map((item) => (
          <Link
            key={item}
            to={`/resources/${resource.id}/${item}`}
            className={cn(
              buttonVariants({ size: 'sm', variant: tab === item ? 'default' : 'ghost' }),
            )}
          >
            {t(tabKeys[item])}
          </Link>
        ))}
      </div>

      {tab === 'overview' ? (
        <div className="grid gap-4 md:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle>{t('resources.overview')}</CardTitle>
              <CardDescription>{t('resources.overviewHint')}</CardDescription>
            </CardHeader>
            <CardContent className="space-y-2 text-sm">
              <p>
                <span className="text-muted-foreground">{t('resources.slugLabel')}</span>{' '}
                {resource.slug}
              </p>
              <p>
                <span className="text-muted-foreground">{t('resources.apiVersion')}</span>{' '}
                {resource.apiVersion}
              </p>
              <p>
                <span className="text-muted-foreground">{t('resources.schemaVersion')}</span>{' '}
                {resource.schemaVersion}
              </p>
              <p>
                <span className="text-muted-foreground">{t('resources.fieldsCount')}</span>{' '}
                {schema.length}
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle>{t('resources.apiAccess')}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2 text-sm">
              <p>
                {t('resources.apiEnabled', {
                  value: resource.settings.apiEnabled ? t('common.yes') : t('common.no'),
                })}
              </p>
              <p>
                {t('resources.publicRead', {
                  value: resource.settings.public.read ? t('common.yes') : t('common.no'),
                })}
              </p>
              <p>
                {t('resources.publicCreate', {
                  value: resource.settings.public.create ? t('common.yes') : t('common.no'),
                })}
              </p>
            </CardContent>
          </Card>
          <Card className="md:col-span-2">
            <CardHeader>
              <CardTitle>{t('resources.fetchExample')}</CardTitle>
              <CardDescription>{t('resources.fetchExampleHint')}</CardDescription>
            </CardHeader>
            <CardContent>
              <ResourceFetchExample resource={resource} showLabel={false} />
            </CardContent>
          </Card>
        </div>
      ) : null}

      {tab === 'schema' ? (
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <div>
              <CardTitle>{t('resources.schema')}</CardTitle>
              <CardDescription>{t('resources.schemaHint')}</CardDescription>
            </div>
            <Button
              disabled={!schemaDirty || saveSchema.isPending}
              onClick={() => saveSchema.mutate()}
            >
              {saveSchema.isPending ? t('common.saving') : t('resources.saveSchema')}
            </Button>
          </CardHeader>
          <CardContent className="space-y-3">
            <SchemaBuilder
              schema={schema}
              onChange={(next) => {
                setDraftSchema(next)
                setMessage(null)
              }}
            />
            {message ? <p className="text-sm text-muted-foreground">{message}</p> : null}
          </CardContent>
        </Card>
      ) : null}

      {tab === 'data' ? (
        <ResourceEntriesPanel
          resourceId={resource.id}
          resourceSlug={resource.slug}
          fields={fieldsQuery.data ?? schema}
          published={resource.status === 'published'}
        />
      ) : null}

      {tab === 'settings' ? (
        <ResourceSettingsPanel
          key={`settings-${resource.id}-${JSON.stringify(resource.settings)}`}
          resource={resource}
          onSaved={() => void queryClient.invalidateQueries({ queryKey: ['resource', resourceId] })}
        />
      ) : null}

      {tab === 'api' ? (
        <div className="space-y-6">
          <ResourceCustomApisPanel
            resource={resource}
            fields={fieldsQuery.data ?? schema}
            onSelectPath={(path) => setPlaygroundPath(path)}
          />
          <ResourceApiPlayground
            key={`api-${resource.id}`}
            resource={resource}
            fields={fieldsQuery.data ?? schema}
            pathPreset={playgroundPath}
          />
        </div>
      ) : null}

      {tab === 'export' ? <ResourceExportPanel resource={resource} /> : null}
    </div>
  )
}
