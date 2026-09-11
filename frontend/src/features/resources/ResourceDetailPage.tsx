import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { clsx } from 'clsx'
import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { DetailPageSkeleton } from '@/components/skeletons'
import { Badge } from '@/components/ui/badge'
import { Button, buttonVariants } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Form } from '@/components/ui/form'
import { ResourceApiPlayground } from '@/features/resources/ResourceApiPlayground'
import { ResourceCustomApisPanel } from '@/features/resources/ResourceCustomApisPanel'
import { ResourceEntriesPanel } from '@/features/resources/ResourceEntriesPanel'
import { ResourceExportPanel } from '@/features/resources/ResourceExportPanel'
import { ResourceFetchExample } from '@/features/resources/ResourceFetchExample'
import { ResourceHooksPanel } from '@/features/resources/ResourceHooksPanel'
import { ResourceSettingsPanel } from '@/features/resources/ResourceSettingsPanel'
import { SchemaBuilder } from '@/features/schema-builder/SchemaBuilder'
import { useAcl } from '@/hooks/useAcl'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import { showSuccess } from '@/lib/toast'
import styles from './ResourceDetailPage.module.css'
import type { SchemaField } from '@/types/field'
import type { Resource } from '@/types/resource'

const TABS = ['overview', 'schema', 'data', 'settings', 'api', 'hooks', 'export'] as const
type Tab = (typeof TABS)[number]

/** Stable identity so child effects don't re-run while fields are loading. */
const EMPTY_FIELDS: SchemaField[] = []

const tabKeys = {
  overview: 'resources.tab.overview',
  schema: 'resources.tab.schema',
  data: 'resources.tab.data',
  settings: 'resources.tab.settings',
  api: 'resources.tab.api',
  hooks: 'resources.tab.hooks',
  export: 'resources.tab.export',
} as const

function isTab(value: string | undefined): value is Tab {
  return TABS.includes(value as Tab)
}

export function ResourceDetailPage() {
  const { t } = useI18n()
  const { user, canResourceTab, canResourceAction, aclEnabled } = useAcl()
  const { id, tab: tabParam, entryId: entryParam } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const resourceId = Number(id)
  const tab: Tab = isTab(tabParam) ? tabParam : 'overview'
  const [draftSchema, setDraftSchema] = useState<SchemaField[] | null>(null)
  const [playgroundPath, setPlaygroundPath] = useState<string | null>(null)

  const visibleTabs = useMemo(
    () => TABS.filter((item) => canResourceTab(resourceId, item)),
    // user identity drives grants; canResourceTab is recreated each render
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [user, resourceId],
  )

  useEffect(() => {
    if (!Number.isFinite(resourceId) || resourceId <= 0) return
    if (!isTab(tabParam)) {
      void navigate(`/resources/${resourceId}/overview`, { replace: true })
      return
    }
    if (visibleTabs.length > 0 && !visibleTabs.includes(tab)) {
      void navigate(`/resources/${resourceId}/${visibleTabs[0]}`, { replace: true })
    }
  }, [navigate, resourceId, tabParam, tab, visibleTabs])

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

  const schema = draftSchema ?? fieldsQuery.data ?? EMPTY_FIELDS
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
    onSuccess: () => {
      void navigate('/resources')
    },
  })

  const saveSchema = useMutation({
    mutationFn: () => {
      const fields = schema.map(({ clientKey: _clientKey, ...field }) => field)
      return api<SchemaField[]>(`/admin/api/resources/${resourceId}/fields`, {
        method: 'PUT',
        body: JSON.stringify({ fields }),
      })
    },
    onSuccess: (data) => {
      setDraftSchema(null)
      showSuccess(t('resources.schemaSaved'))
      queryClient.setQueryData(['resource-fields', resourceId], data)
    },
  })

  const resource = query.data

  if (query.isLoading) {
    return <DetailPageSkeleton />
  }

  if (!resource) {
    return (
      <div className={styles.root}>
        <p className={styles.error}>{t('resources.notFound')}</p>
        <Button
          variant="outline"
          onClick={() => {
            void navigate('/resources')
          }}
        >
          {t('common.back')}
        </Button>
      </div>
    )
  }

  return (
    <div className={styles.stack}>
      <div className={styles.header}>
        <div>
          <p className={styles.crumb}>
            <Link to="/resources" className={styles.crumbLink}>
              {t('nav.resources')}
            </Link>{' '}
            / {resource.label}
          </p>
          <h1 className={styles.title}>{resource.label}</h1>
          <div className={styles.meta}>
            <Badge>{resource.status}</Badge>
            <span className={styles.endpoint}>{resource.endpoint}</span>
          </div>
        </div>
        <div className={styles.actions}>
          {resource.status !== 'published' && canResourceAction(resourceId, 'update') ? (
            <Button disabled={publish.isPending} onClick={() => publish.mutate()}>
              {t('resources.publish')}
            </Button>
          ) : null}
          {!resource.isSystem && canResourceAction(resourceId, 'delete') ? (
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

      <div className={styles.tabs}>
        {visibleTabs.map((item) => (
          <Link
            key={item}
            to={`/resources/${resource.id}/${item}`}
            className={clsx(
              buttonVariants({ size: 'sm', variant: tab === item ? 'default' : 'ghost' }),
            )}
          >
            {t(tabKeys[item])}
          </Link>
        ))}
      </div>

      {aclEnabled && visibleTabs.length === 0 ? (
        <p className={styles.muted}>{t('users.acl.noResourceTabs')}</p>
      ) : null}

      {tab === 'overview' ? (
        <div className={styles.overviewGrid}>
          <Card>
            <CardHeader>
              <CardTitle>{t('resources.overview')}</CardTitle>
              <CardDescription>{t('resources.overviewHint')}</CardDescription>
            </CardHeader>
            <CardContent className={styles.cardStack}>
              <p>
                <span className={styles.muted}>{t('resources.slugLabel')}</span> {resource.slug}
              </p>
              <p>
                <span className={styles.muted}>{t('resources.apiVersion')}</span>{' '}
                {resource.apiVersion}
              </p>
              <p>
                <span className={styles.muted}>{t('resources.schemaVersion')}</span>{' '}
                {resource.schemaVersion}
              </p>
              <p>
                <span className={styles.muted}>{t('resources.fieldsCount')}</span> {schema.length}
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle>{t('resources.apiAccess')}</CardTitle>
            </CardHeader>
            <CardContent className={styles.cardStack}>
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

          <Card className={styles.cardSpan}>
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
          <Form onSubmit={() => saveSchema.mutate()}>
            <CardHeader className={styles.schemaHeader}>
              <div>
                <CardTitle>{t('resources.schema')}</CardTitle>
                <CardDescription>{t('resources.schemaHint')}</CardDescription>
              </div>
              <Button
                type="submit"
                disabled={
                  !schemaDirty || saveSchema.isPending || !canResourceAction(resourceId, 'update')
                }
              >
                {saveSchema.isPending ? t('common.saving') : t('resources.saveSchema')}
              </Button>
            </CardHeader>
            <CardContent className={styles.schemaBody}>
              <SchemaBuilder
                schema={schema}
                onChange={(next) => {
                  setDraftSchema(next)
                }}
              />
            </CardContent>
          </Form>
        </Card>
      ) : null}

      {tab === 'data' ? (
        <ResourceEntriesPanel
          resourceId={resource.id}
          resourceSlug={resource.slug}
          fields={fieldsQuery.data ?? schema}
          published={resource.status === 'published'}
          listColumns={resource.settings.list?.columns}
          entryParam={entryParam ?? null}
          entryPath={(entry) =>
            entry === null
              ? `/resources/${resource.id}/data`
              : `/resources/${resource.id}/data/${entry}`
          }
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
        <div className={styles.stack}>
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

      {tab === 'hooks' ? <ResourceHooksPanel resourceId={resource.id} /> : null}

      {tab === 'export' ? <ResourceExportPanel resource={resource} /> : null}
    </div>
  )
}
