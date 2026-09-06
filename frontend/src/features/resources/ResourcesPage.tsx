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
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import type { Resource } from '@/types/resource'

export function ResourcesPage() {
  const { t } = useI18n()
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
          <h1 className="text-2xl font-semibold">{t('resources.title')}</h1>
          <p className="text-sm text-muted-foreground">{t('resources.subtitle')}</p>
        </div>
        <Button onClick={() => navigate('/resources/new')}>{t('resources.create')}</Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>{t('resources.all')}</CardTitle>
          <CardDescription>{t('resources.total', { count: resources.length })}</CardDescription>
        </CardHeader>
        <CardContent>
          {resources.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t('resources.empty')}</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('common.label')}</TableHead>
                  <TableHead>{t('common.slug')}</TableHead>
                  <TableHead>{t('common.endpoint')}</TableHead>
                  <TableHead>{t('common.status')}</TableHead>
                  <TableHead className="text-right">{t('common.actions')}</TableHead>
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
                          {t('resources.publish')}
                        </Button>
                      ) : null}
                      {!resource.isSystem ? (
                        <Button
                          size="sm"
                          variant="destructive"
                          disabled={remove.isPending}
                          onClick={() => {
                            if (confirm(t('resources.deleteConfirm', { label: resource.label }))) {
                              remove.mutate(resource.id)
                            }
                          }}
                        >
                          {t('common.delete')}
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
