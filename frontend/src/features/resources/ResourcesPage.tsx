import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { api } from '@/lib/api'
import type { Resource } from '@/types/resource'

export function ResourcesPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const query = useQuery({
    queryKey: ['resources'],
    queryFn: () => api<Resource[]>('/admin/api/resources'),
  })

  const publish = useMutation({
    mutationFn: (id: number) =>
      api<Resource>(`/admin/api/resources/${id}/publish`, { method: 'POST', body: '{}' }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['resources'] }),
  })

  const remove = useMutation({
    mutationFn: (id: number) => api<void>(`/admin/api/resources/${id}`, { method: 'DELETE' }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['resources'] }),
  })

  const resources = query.data ?? []

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold">Resources</h1>
          <p className="text-sm text-muted-foreground">Content types published as API endpoints.</p>
        </div>
        <Button onClick={() => navigate('/resources/new')}>+ Create resource</Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>All resources</CardTitle>
          <CardDescription>{resources.length} total</CardDescription>
        </CardHeader>
        <CardContent>
          {resources.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              No resources yet. Create one to get a draft API endpoint.
            </p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Label</TableHead>
                  <TableHead>Slug</TableHead>
                  <TableHead>Endpoint</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {resources.map((resource) => (
                  <TableRow key={resource.id}>
                    <TableCell>
                      <Link
                        className="font-medium hover:underline"
                        to={`/resources/${resource.id}`}
                      >
                        {resource.label}
                      </Link>
                    </TableCell>
                    <TableCell className="font-mono text-xs">{resource.slug}</TableCell>
                    <TableCell className="font-mono text-xs">{resource.endpoint}</TableCell>
                    <TableCell>
                      <Badge
                        variant={
                          resource.status === 'published'
                            ? 'default'
                            : resource.status === 'archived'
                              ? 'outline'
                              : 'secondary'
                        }
                      >
                        {resource.status}
                      </Badge>
                    </TableCell>
                    <TableCell className="space-x-2 text-right">
                      {resource.status !== 'published' ? (
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={publish.isPending}
                          onClick={() => publish.mutate(resource.id)}
                        >
                          Publish
                        </Button>
                      ) : null}
                      {!resource.isSystem ? (
                        <Button
                          size="sm"
                          variant="destructive"
                          disabled={remove.isPending}
                          onClick={() => {
                            if (confirm(`Delete resource "${resource.label}"?`)) {
                              remove.mutate(resource.id)
                            }
                          }}
                        >
                          Delete
                        </Button>
                      ) : null}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
