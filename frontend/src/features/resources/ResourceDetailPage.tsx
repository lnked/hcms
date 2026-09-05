import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { api } from '@/lib/api'
import type { Resource } from '@/types/resource'

export function ResourceDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const resourceId = Number(id)

  const query = useQuery({
    queryKey: ['resource', resourceId],
    queryFn: () => api<Resource>(`/admin/api/resources/${resourceId}`),
    enabled: Number.isFinite(resourceId) && resourceId > 0,
  })

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
              <span className="text-muted-foreground">Content type id:</span>{' '}
              {resource.contentTypeId}
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>API access</CardTitle>
            <CardDescription>Public flags (enforced in Phase 6+).</CardDescription>
          </CardHeader>
          <CardContent className="space-y-2 text-sm">
            <p>API enabled: {resource.settings.apiEnabled ? 'yes' : 'no'}</p>
            <p>Public read: {resource.settings.public.read ? 'yes' : 'no'}</p>
            <p>Public create: {resource.settings.public.create ? 'yes' : 'no'}</p>
            <p>Public update: {resource.settings.public.update ? 'yes' : 'no'}</p>
            <p>Public delete: {resource.settings.public.delete ? 'yes' : 'no'}</p>
          </CardContent>
        </Card>
        <Card className="md:col-span-2">
          <CardHeader>
            <CardTitle>Next</CardTitle>
          </CardHeader>
          <CardContent className="text-sm text-muted-foreground">
            Schema Builder (fields) arrives in Phase 4. Data CRUD UI in Phase 7. Dynamic public API
            in Phase 6.
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
