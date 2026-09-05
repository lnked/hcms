import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { SchemaBuilder } from '@/features/schema-builder/SchemaBuilder'
import { api } from '@/lib/api'
import type { SchemaField } from '@/types/field'
import type { Resource } from '@/types/resource'

type Tab = 'overview' | 'schema' | 'api'

export function ResourceDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const resourceId = Number(id)
  const [tab, setTab] = useState<Tab>('overview')
  const [draftSchema, setDraftSchema] = useState<SchemaField[] | null>(null)
  const [message, setMessage] = useState<string | null>(null)

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
      setMessage('Schema saved')
      queryClient.setQueryData(['resource-fields', resourceId], data)
    },
    onError: (err) => setMessage(err instanceof Error ? err.message : 'Save failed'),
  })

  const resource = query.data

  if (query.isLoading) {
    return <p className="text-sm text-muted-foreground">Loading…</p>
  }

  if (!resource) {
    return (
      <div className="space-y-4">
        <p className="text-sm text-destructive">Resource not found.</p>
        <Button variant="outline" onClick={() => navigate('/resources')}>
          Back
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
              Resources
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
              Publish
            </Button>
          ) : null}
          {!resource.isSystem ? (
            <Button
              variant="destructive"
              disabled={remove.isPending}
              onClick={() => {
                if (confirm(`Delete resource "${resource.label}"?`)) {
                  remove.mutate()
                }
              }}
            >
              Delete
            </Button>
          ) : null}
        </div>
      </div>

      <div className="flex gap-2 border-b pb-2">
        {(['overview', 'schema', 'api'] as Tab[]).map((item) => (
          <Button
            key={item}
            size="sm"
            variant={tab === item ? 'default' : 'ghost'}
            onClick={() => setTab(item)}
          >
            {item[0].toUpperCase() + item.slice(1)}
          </Button>
        ))}
      </div>

      {tab === 'overview' ? (
        <div className="grid gap-4 md:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle>Overview</CardTitle>
              <CardDescription>Metadata for this resource.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-2 text-sm">
              <p>
                <span className="text-muted-foreground">Slug:</span> {resource.slug}
              </p>
              <p>
                <span className="text-muted-foreground">API version:</span> {resource.apiVersion}
              </p>
              <p>
                <span className="text-muted-foreground">Schema version:</span>{' '}
                {resource.schemaVersion}
              </p>
              <p>
                <span className="text-muted-foreground">Fields:</span> {schema.length}
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle>API access</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2 text-sm">
              <p>API enabled: {resource.settings.apiEnabled ? 'yes' : 'no'}</p>
              <p>Public read: {resource.settings.public.read ? 'yes' : 'no'}</p>
              <p>Public create: {resource.settings.public.create ? 'yes' : 'no'}</p>
            </CardContent>
          </Card>
        </div>
      ) : null}

      {tab === 'schema' ? (
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <div>
              <CardTitle>Schema</CardTitle>
              <CardDescription>Source of truth for DB, API and Admin UI.</CardDescription>
            </div>
            <Button
              disabled={!schemaDirty || saveSchema.isPending}
              onClick={() => saveSchema.mutate()}
            >
              {saveSchema.isPending ? 'Saving…' : 'Save schema'}
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

      {tab === 'api' ? (
        <Card>
          <CardHeader>
            <CardTitle>API</CardTitle>
            <CardDescription>Endpoints after publish (runtime Phase 6).</CardDescription>
          </CardHeader>
          <CardContent className="space-y-2 font-mono text-sm">
            <p>GET {resource.endpoint}</p>
            <p>GET {resource.endpoint}/:id</p>
            <p>POST {resource.endpoint}</p>
            <p>PATCH {resource.endpoint}/:id</p>
            <p>DELETE {resource.endpoint}/:id</p>
            <Button
              variant="outline"
              className="mt-4"
              onClick={() => window.open('/api/docs', '_blank')}
            >
              Open Documentation
            </Button>
          </CardContent>
        </Card>
      ) : null}
    </div>
  )
}
