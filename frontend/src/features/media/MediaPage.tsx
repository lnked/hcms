import { useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { useI18n } from '@/i18n'
import { api, apiPage, apiUpload } from '@/lib/api'
import type { MediaItem } from '@/types/media'

export function MediaPage() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const inputRef = useRef<HTMLInputElement>(null)
  const [page, setPage] = useState(1)
  const [error, setError] = useState<string | null>(null)

  const list = useQuery({
    queryKey: ['media', page],
    queryFn: () => apiPage<MediaItem>(`/admin/api/media?page=${page}&limit=24`),
  })

  const upload = useMutation({
    mutationFn: (file: File) => apiUpload<MediaItem>('/admin/api/media', file),
    onSuccess: () => {
      setError(null)
      void queryClient.invalidateQueries({ queryKey: ['media'] })
    },
    onError: (err) => setError(err instanceof Error ? err.message : t('common.uploadFailed')),
  })

  const remove = useMutation({
    mutationFn: (id: number) => api<void>(`/admin/api/media/${id}`, { method: 'DELETE' }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['media'] }),
  })

  const items = list.data?.data ?? []
  const meta = list.data?.meta

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">{t('media.title')}</h1>
          <p className="text-sm text-muted-foreground">{t('media.subtitle')}</p>
        </div>
        <div>
          <input
            ref={inputRef}
            type="file"
            className="hidden"
            onChange={(e) => {
              const file = e.target.files?.[0]
              if (file) upload.mutate(file)
              e.target.value = ''
            }}
          />
          <Button disabled={upload.isPending} onClick={() => inputRef.current?.click()}>
            {upload.isPending ? t('media.uploading') : t('media.upload')}
          </Button>
        </div>
      </div>

      {error ? <p className="text-sm text-destructive">{error}</p> : null}

      <Card>
        <CardHeader>
          <CardTitle>{t('media.library')}</CardTitle>
          <CardDescription>{t('media.publicUrl')}</CardDescription>
        </CardHeader>
        <CardContent>
          {list.isLoading ? (
            <p className="text-sm text-muted-foreground">{t('common.loading')}</p>
          ) : items.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t('media.empty')}</p>
          ) : (
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
              {items.map((item) => (
                <div key={item.id} className="overflow-hidden rounded-lg border">
                  <div className="flex h-36 items-center justify-center bg-muted">
                    {item.mime.startsWith('image/') ? (
                      <img
                        src={item.url}
                        alt={item.originalName}
                        className="h-full w-full object-cover"
                      />
                    ) : (
                      <span className="px-2 text-center text-xs text-muted-foreground">
                        {item.mime}
                      </span>
                    )}
                  </div>
                  <div className="space-y-2 p-3">
                    <p className="truncate text-sm font-medium" title={item.originalName}>
                      {item.originalName}
                    </p>
                    <p className="font-mono text-xs text-muted-foreground">
                      #{item.id} · {(item.size / 1024).toFixed(1)} KB
                    </p>
                    <div className="flex gap-2">
                      <a
                        href={item.url}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex h-8 items-center rounded-md border border-input px-3 text-sm hover:bg-accent"
                      >
                        {t('common.open')}
                      </a>
                      <Button
                        size="sm"
                        variant="destructive"
                        onClick={() => {
                          if (confirm(t('media.deleteConfirm', { name: item.originalName }))) {
                            remove.mutate(item.id)
                          }
                        }}
                      >
                        {t('common.delete')}
                      </Button>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}

          {meta ? (
            <div className="mt-4 flex items-center justify-between text-sm text-muted-foreground">
              <span>{t('common.pageOf', { page: meta.page, totalPages: meta.totalPages })}</span>
              <div className="flex gap-2">
                <Button
                  size="sm"
                  variant="outline"
                  disabled={page <= 1}
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                >
                  {t('common.prev')}
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  disabled={page >= meta.totalPages}
                  onClick={() => setPage((p) => p + 1)}
                >
                  {t('common.next')}
                </Button>
              </div>
            </div>
          ) : null}
        </CardContent>
      </Card>
    </div>
  )
}
