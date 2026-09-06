import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { Trash2, Upload } from 'lucide-react'
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
import { copyToClipboard } from '@/lib/clipboard'
import type { Resource } from '@/types/resource'

export function ResourcesPage() {
  const { t } = useI18n()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [copiedToast, setCopiedToast] = useState(false)
  const toastTimer = useRef<ReturnType<typeof setTimeout> | null>(null)

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

  useEffect(() => {
    return () => {
      if (toastTimer.current) clearTimeout(toastTimer.current)
    }
  }, [])

  const copyEndpoint = async (endpoint: string) => {
    try {
      await copyToClipboard(endpoint)
      setCopiedToast(true)
      if (toastTimer.current) clearTimeout(toastTimer.current)
      toastTimer.current = setTimeout(() => setCopiedToast(false), 2000)
    } catch {
      // ignore — nothing to show if clipboard is fully blocked
    }
  }

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
                        to={`/resources/${resource.id}/overview`}
                      >
                        {resource.label}
                      </Link>
                    </TableCell>
                    <TableCell className="font-mono text-xs">{resource.slug}</TableCell>
                    <TableCell className="font-mono text-xs">
                      <button
                        type="button"
                        className="cursor-pointer underline decoration-dashed underline-offset-2 hover:text-primary"
                        onClick={() => void copyEndpoint(resource.endpoint)}
                      >
                        {resource.endpoint}
                      </button>
                    </TableCell>
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
                    <TableCell className="text-right">
                      <div className="inline-flex items-center justify-end gap-1">
                        {resource.status !== 'published' ? (
                          <Button
                            size="icon"
                            variant="ghost"
                            disabled={publish.isPending}
                            aria-label={t('resources.publish')}
                            title={t('resources.publish')}
                            onClick={() => publish.mutate(resource.id)}
                          >
                            <Upload className="h-4 w-4" />
                          </Button>
                        ) : null}
                        {!resource.isSystem ? (
                          <Button
                            size="icon"
                            variant="ghost"
                            disabled={remove.isPending}
                            aria-label={t('common.delete')}
                            title={t('common.delete')}
                            onClick={() => {
                              if (confirm(t('resources.deleteConfirm', { label: resource.label }))) {
                                remove.mutate(resource.id)
                              }
                            }}
                          >
                            <Trash2 className="h-4 w-4 text-destructive" />
                          </Button>
                        ) : null}
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      {copiedToast ? (
        <div
          role="status"
          className="fixed right-4 bottom-4 z-50 rounded-md bg-emerald-600 px-4 py-2 text-sm text-white shadow-lg"
        >
          {t('common.copied')}
        </div>
      ) : null}
    </div>
  )
}
