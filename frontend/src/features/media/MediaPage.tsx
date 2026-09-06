import { useCallback, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Upload } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { useI18n } from '@/i18n'
import { api, apiPage, apiUpload } from '@/lib/api'
import { cn } from '@/lib/utils'
import type { MediaItem } from '@/types/media'

export function MediaPage() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const inputRef = useRef<HTMLInputElement>(null)
  const [page, setPage] = useState(1)
  const [error, setError] = useState<string | null>(null)
  const [dragging, setDragging] = useState(false)
  const [progress, setProgress] = useState<{ done: number; total: number } | null>(null)

  const list = useQuery({
    queryKey: ['media', page],
    queryFn: () => apiPage<MediaItem>(`/admin/api/media?page=${page}&limit=24`),
  })

  const uploadMany = useMutation({
    mutationFn: async (files: File[]) => {
      const queue = files.filter((f) => f.size > 0)
      if (queue.length === 0) {
        throw new Error(t('media.noFilesSelected'))
      }
      setProgress({ done: 0, total: queue.length })
      const failures: string[] = []
      for (let i = 0; i < queue.length; i++) {
        try {
          await apiUpload<MediaItem>('/admin/api/media', queue[i]!)
        } catch (err) {
          const name = queue[i]!.name
          const msg = err instanceof Error ? err.message : t('common.uploadFailed')
          failures.push(`${name}: ${msg}`)
        }
        setProgress({ done: i + 1, total: queue.length })
      }
      return { ok: queue.length - failures.length, failures }
    },
    onSuccess: (result) => {
      void queryClient.invalidateQueries({ queryKey: ['media'] })
      if (result.failures.length > 0) {
        setError(
          t('media.uploadPartial', {
            ok: result.ok,
            failed: result.failures.length,
          }) +
            ' — ' +
            result.failures.slice(0, 3).join('; '),
        )
      } else {
        setError(null)
      }
      setProgress(null)
    },
    onError: (err) => {
      setProgress(null)
      setError(err instanceof Error ? err.message : t('common.uploadFailed'))
    },
  })

  const remove = useMutation({
    mutationFn: (id: number) => api<void>(`/admin/api/media/${id}`, { method: 'DELETE' }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['media'] }),
  })

  const enqueue = useCallback(
    (files: FileList | File[] | null) => {
      if (!files || uploadMany.isPending) return
      const next = Array.from(files)
      if (next.length === 0) return
      uploadMany.mutate(next)
    },
    [uploadMany],
  )

  const items = list.data?.data ?? []
  const meta = list.data?.meta
  const busy = uploadMany.isPending

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">{t('media.title')}</h1>
        <p className="text-sm text-muted-foreground">{t('media.subtitle')}</p>
      </div>

      <input
        ref={inputRef}
        type="file"
        multiple
        className="hidden"
        onChange={(e) => {
          enqueue(e.target.files)
          e.target.value = ''
        }}
      />

      <div
        role="button"
        tabIndex={0}
        aria-label={t('media.dropzone')}
        onKeyDown={(e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault()
            if (!busy) inputRef.current?.click()
          }
        }}
        onClick={() => {
          if (!busy) inputRef.current?.click()
        }}
        onDragEnter={(e) => {
          e.preventDefault()
          e.stopPropagation()
          setDragging(true)
        }}
        onDragOver={(e) => {
          e.preventDefault()
          e.stopPropagation()
          setDragging(true)
        }}
        onDragLeave={(e) => {
          e.preventDefault()
          e.stopPropagation()
          const next = e.relatedTarget as Node | null
          if (next && e.currentTarget.contains(next)) return
          setDragging(false)
        }}
        onDrop={(e) => {
          e.preventDefault()
          e.stopPropagation()
          setDragging(false)
          enqueue(e.dataTransfer.files)
        }}
        className={cn(
          'flex cursor-pointer flex-col items-center justify-center gap-3 rounded-lg border border-dashed px-6 py-10 text-center transition-colors',
          dragging
            ? 'border-primary bg-primary/5'
            : 'border-border bg-muted/30 hover:border-primary/50 hover:bg-muted/50',
          busy && 'pointer-events-none opacity-70',
        )}
      >
        <Upload className="h-8 w-8 text-muted-foreground" />
        <div className="space-y-1">
          <p className="text-sm font-medium">{t('media.dropzone')}</p>
          <p className="text-xs text-muted-foreground">{t('media.dropzoneHint')}</p>
        </div>
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={busy}
          onClick={(e) => {
            e.stopPropagation()
            inputRef.current?.click()
          }}
        >
          {busy
            ? progress
              ? t('media.uploadingProgress', { done: progress.done, total: progress.total })
              : t('media.uploading')
            : t('media.upload')}
        </Button>
        {progress ? (
          <div className="h-1.5 w-48 overflow-hidden rounded-full bg-muted">
            <div
              className="h-full bg-primary transition-[width] duration-200"
              style={{ width: `${Math.round((progress.done / progress.total) * 100)}%` }}
            />
          </div>
        ) : null}
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
