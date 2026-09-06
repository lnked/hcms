import { useCallback, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { LayoutGrid, Table2, Upload } from 'lucide-react'
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
import { api, apiPage, apiUpload } from '@/lib/api'
import { cn } from '@/lib/utils'
import type { MediaItem } from '@/types/media'

type MediaView = 'list' | 'table'

const VIEW_KEY = 'hcms.media.view'

function readView(): MediaView {
  try {
    const v = localStorage.getItem(VIEW_KEY)
    return v === 'table' ? 'table' : 'list'
  } catch {
    return 'list'
  }
}

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

export function MediaPage() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const inputRef = useRef<HTMLInputElement>(null)
  const [page, setPage] = useState(1)
  const [error, setError] = useState<string | null>(null)
  const [dragging, setDragging] = useState(false)
  const [progress, setProgress] = useState<{ done: number; total: number } | null>(null)
  const [view, setView] = useState<MediaView>(readView)

  const list = useQuery({
    queryKey: ['media', page],
    queryFn: () => apiPage<MediaItem>(`/admin/api/media?page=${page}&limit=48`),
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

  const changeView = (next: MediaView) => {
    setView(next)
    try {
      localStorage.setItem(VIEW_KEY, next)
    } catch {
      // ignore
    }
  }

  const confirmDelete = (item: MediaItem) => {
    if (confirm(t('media.deleteConfirm', { name: item.originalName }))) {
      remove.mutate(item.id)
    }
  }

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
          'sticky top-0 z-20 flex cursor-pointer items-center justify-between gap-4 rounded-lg border border-dashed px-4 py-3 transition-colors',
          'bg-background/95 shadow-sm backdrop-blur supports-[backdrop-filter]:bg-background/85',
          dragging
            ? 'border-primary bg-primary/5'
            : 'border-border hover:border-primary/50 hover:bg-muted/40',
          busy && 'pointer-events-none opacity-70',
        )}
      >
        <div className="flex min-w-0 items-center gap-3">
          <Upload className="h-5 w-5 shrink-0 text-muted-foreground" />
          <div className="min-w-0 space-y-0.5">
            <p className="truncate text-sm font-medium">{t('media.dropzone')}</p>
            <p className="truncate text-xs text-muted-foreground">{t('media.dropzoneHint')}</p>
          </div>
        </div>
        <div className="flex shrink-0 items-center gap-3">
          {progress ? (
            <div className="hidden h-1.5 w-28 overflow-hidden rounded-full bg-muted sm:block">
              <div
                className="h-full bg-primary transition-[width] duration-200"
                style={{ width: `${Math.round((progress.done / progress.total) * 100)}%` }}
              />
            </div>
          ) : null}
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
        </div>
      </div>

      {error ? <p className="text-sm text-destructive">{error}</p> : null}

      <Card>
        <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3 space-y-0">
          <div className="space-y-1.5">
            <CardTitle>{t('media.library')}</CardTitle>
            <CardDescription>{t('media.publicUrl')}</CardDescription>
          </div>
          <div className="flex items-center gap-1 rounded-md border p-0.5">
            <Button
              type="button"
              size="icon"
              variant={view === 'list' ? 'secondary' : 'ghost'}
              className="h-8 w-8"
              aria-pressed={view === 'list'}
              title={t('media.viewList')}
              aria-label={t('media.viewList')}
              onClick={() => changeView('list')}
            >
              <LayoutGrid className="h-4 w-4" />
            </Button>
            <Button
              type="button"
              size="icon"
              variant={view === 'table' ? 'secondary' : 'ghost'}
              className="h-8 w-8"
              aria-pressed={view === 'table'}
              title={t('media.viewTable')}
              aria-label={t('media.viewTable')}
              onClick={() => changeView('table')}
            >
              <Table2 className="h-4 w-4" />
            </Button>
          </div>
        </CardHeader>
        <CardContent>
          {list.isLoading ? (
            <p className="text-sm text-muted-foreground">{t('common.loading')}</p>
          ) : items.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t('media.empty')}</p>
          ) : view === 'list' ? (
            <div className="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-8">
              {items.map((item) => (
                <div key={item.id} className="overflow-hidden rounded-md border">
                  <div className="flex aspect-square items-center justify-center bg-muted">
                    {item.mime.startsWith('image/') ? (
                      <img
                        src={item.url}
                        alt={item.originalName}
                        className="h-full w-full object-cover"
                      />
                    ) : (
                      <span className="px-1.5 text-center text-[10px] leading-tight text-muted-foreground">
                        {item.mime}
                      </span>
                    )}
                  </div>
                  <div className="space-y-1.5 p-2">
                    <p className="truncate text-xs font-medium" title={item.originalName}>
                      {item.originalName}
                    </p>
                    <p className="font-mono text-[10px] text-muted-foreground">
                      #{item.id} · {formatSize(item.size)}
                    </p>
                    <div className="flex gap-1">
                      <a
                        href={item.url}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex h-7 flex-1 items-center justify-center rounded-md border border-input px-1.5 text-[11px] hover:bg-accent"
                      >
                        {t('common.open')}
                      </a>
                      <Button
                        size="sm"
                        variant="destructive"
                        className="h-7 px-2 text-[11px]"
                        onClick={() => confirmDelete(item)}
                      >
                        {t('common.delete')}
                      </Button>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-14">{t('media.preview')}</TableHead>
                  <TableHead>{t('common.name')}</TableHead>
                  <TableHead className="hidden md:table-cell">{t('common.type')}</TableHead>
                  <TableHead className="hidden sm:table-cell">{t('media.size')}</TableHead>
                  <TableHead className="hidden lg:table-cell">{t('media.created')}</TableHead>
                  <TableHead className="text-right">{t('common.actions')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {items.map((item) => (
                  <TableRow key={item.id}>
                    <TableCell>
                      <div className="flex h-10 w-10 items-center justify-center overflow-hidden rounded bg-muted">
                        {item.mime.startsWith('image/') ? (
                          <img
                            src={item.url}
                            alt=""
                            className="h-full w-full object-cover"
                          />
                        ) : (
                          <span className="text-[9px] text-muted-foreground">file</span>
                        )}
                      </div>
                    </TableCell>
                    <TableCell>
                      <div className="min-w-0">
                        <p className="truncate font-medium" title={item.originalName}>
                          {item.originalName}
                        </p>
                        <p className="font-mono text-xs text-muted-foreground">#{item.id}</p>
                      </div>
                    </TableCell>
                    <TableCell className="hidden max-w-[12rem] truncate text-xs text-muted-foreground md:table-cell">
                      {item.mime}
                    </TableCell>
                    <TableCell className="hidden whitespace-nowrap text-xs text-muted-foreground sm:table-cell">
                      {formatSize(item.size)}
                    </TableCell>
                    <TableCell className="hidden whitespace-nowrap text-xs text-muted-foreground lg:table-cell">
                      {item.createdAt}
                    </TableCell>
                    <TableCell className="text-right">
                      <div className="flex justify-end gap-2">
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
                          onClick={() => confirmDelete(item)}
                        >
                          {t('common.delete')}
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
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
