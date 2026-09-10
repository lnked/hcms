import { useCallback, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { clsx } from 'clsx'
import { LayoutGrid, Sparkles, Table2, Upload } from 'lucide-react'
import { MediaGridSkeleton, TableSkeleton } from '@/components/skeletons'
import { EmptyState } from '@/components/EmptyState'
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
import type { MediaItem } from '@/types/media'
import { OptimizeImageDialog } from './OptimizeImageDialog'
import styles from './MediaPage.module.css'

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
  const [selectedIds, setSelectedIds] = useState<number[]>([])
  const [optimizeIds, setOptimizeIds] = useState<number[] | null>(null)

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
    onSuccess: (_data, id) => {
      setSelectedIds((prev) => prev.filter((x) => x !== id))
      void queryClient.invalidateQueries({ queryKey: ['media'] })
    },
  })

  const bulkRemove = useMutation({
    mutationFn: (ids: number[]) =>
      api<{ deleted: number }>('/admin/api/media/bulk-delete', {
        method: 'POST',
        body: JSON.stringify({ ids }),
      }),
    onSuccess: () => {
      setSelectedIds([])
      setError(null)
      void queryClient.invalidateQueries({ queryKey: ['media'] })
    },
    onError: (err) => {
      setError(err instanceof Error ? err.message : t('media.bulkDeleteFailed'))
    },
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

  const canOptimize = (item: MediaItem) =>
    item.mime.startsWith('image/') && item.mime !== 'image/svg+xml' && item.mime !== 'image/gif'

  const items = list.data?.data ?? []
  const meta = list.data?.meta
  const busy = uploadMany.isPending
  const allSelected = items.length > 0 && items.every((item) => selectedIds.includes(item.id))
  const someSelected = items.some((item) => selectedIds.includes(item.id))

  const toggleAll = () => {
    if (allSelected) {
      setSelectedIds((prev) => prev.filter((id) => !items.some((item) => item.id === id)))
    } else {
      const next = new Set(selectedIds)
      for (const item of items) next.add(item.id)
      setSelectedIds([...next])
    }
  }

  const toggleOne = (id: number) => {
    setSelectedIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))
  }

  const goToPage = (next: number) => {
    setSelectedIds([])
    setPage(next)
  }

  return (
    <div className={clsx(styles.root)}>
      <div className={clsx(styles.pageHeader)}>
        <h1 className={clsx(styles.title)}>{t('media.title')}</h1>
        <p className={clsx(styles.subtitle)}>{t('media.subtitle')}</p>
      </div>

      <input
        ref={inputRef}
        type="file"
        multiple
        className={clsx(styles.hiddenInput)}
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
        className={clsx(
          styles.dropzone,
          dragging && styles.dropzoneActive,
          busy && styles.dropzoneBusy,
        )}
      >
        <div className={clsx(styles.dropzoneLeft)}>
          <Upload className={clsx(styles.uploadIcon)} />
          <div className={clsx(styles.dropzoneText)}>
            <p className={clsx(styles.dropzoneTitle)}>{t('media.dropzone')}</p>
            <p className={clsx(styles.dropzoneHint)}>{t('media.dropzoneHint')}</p>
          </div>
        </div>
        <div className={clsx(styles.dropzoneRight)}>
          {progress ? (
            <div className={clsx(styles.progressTrack)}>
              <div
                className={clsx(styles.progressBar)}
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

      {error ? <p className={clsx(styles.error)}>{error}</p> : null}

      <Card>
        <CardHeader className={clsx(styles.cardHeader)}>
          <div className={clsx(styles.headerIntro)}>
            <CardTitle>{t('media.library')}</CardTitle>
            <CardDescription>{t('media.publicUrl')}</CardDescription>
          </div>
          <div className={clsx(styles.headerActions)}>
            {selectedIds.length > 0 ? (
              <>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => {
                    const ids = items
                      .filter((i) => selectedIds.includes(i.id) && canOptimize(i))
                      .map((i) => i.id)
                    if (ids.length === 0) {
                      setError(t('media.optimizeNoRaster'))
                      return
                    }
                    setOptimizeIds(ids)
                  }}
                >
                  {t('media.bulkOptimize', { count: selectedIds.length })}
                </Button>
                <Button
                  variant="destructive"
                  size="sm"
                  disabled={bulkRemove.isPending}
                  onClick={() => {
                    if (confirm(t('media.bulkDeleteConfirm', { count: selectedIds.length }))) {
                      bulkRemove.mutate(selectedIds)
                    }
                  }}
                >
                  {bulkRemove.isPending
                    ? t('media.bulkDeleting')
                    : t('media.bulkDelete', { count: selectedIds.length })}
                </Button>
              </>
            ) : null}
            <div className={clsx(styles.viewToggle)}>
              <Button
                type="button"
                size="icon"
                variant={view === 'list' ? 'secondary' : 'ghost'}
                className={clsx(styles.viewBtn)}
                aria-pressed={view === 'list'}
                title={t('media.viewList')}
                aria-label={t('media.viewList')}
                onClick={() => changeView('list')}
              >
                <LayoutGrid className={clsx(styles.iconSm)} />
              </Button>
              <Button
                type="button"
                size="icon"
                variant={view === 'table' ? 'secondary' : 'ghost'}
                className={clsx(styles.viewBtn)}
                aria-pressed={view === 'table'}
                title={t('media.viewTable')}
                aria-label={t('media.viewTable')}
                onClick={() => changeView('table')}
              >
                <Table2 className={clsx(styles.iconSm)} />
              </Button>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          {list.isLoading ? (
            view === 'table' ? (
              <TableSkeleton columns={6} rows={6} />
            ) : (
              <MediaGridSkeleton />
            )
          ) : items.length === 0 ? (
            <EmptyState title={t('media.empty')} />
          ) : view === 'list' ? (
            <div className={clsx(styles.grid)}>
              {items.map((item) => (
                <div key={item.id} className={clsx(styles.mediaCard)}>
                  <div className={clsx(styles.thumb)}>
                    {item.mime.startsWith('image/') ? (
                      <img
                        src={item.url}
                        alt={item.originalName}
                        className={clsx(styles.thumbImg)}
                      />
                    ) : (
                      <span className={clsx(styles.mimeFallback)}>{item.mime}</span>
                    )}
                  </div>
                  <div className={clsx(styles.cardMeta)}>
                    <p className={clsx(styles.cardName)} title={item.originalName}>
                      {item.originalName}
                    </p>
                    <p className={clsx(styles.cardMetaLine)}>
                      #{item.id} · {formatSize(item.size)}
                    </p>
                    <div className={clsx(styles.cardActions)}>
                      <a
                        href={item.url}
                        target="_blank"
                        rel="noreferrer"
                        className={clsx(styles.openLink)}
                      >
                        {t('common.open')}
                      </a>
                      {canOptimize(item) ? (
                        <Button
                          size="sm"
                          variant="outline"
                          className={clsx(styles.cardIconBtn)}
                          title={t('media.optimize')}
                          aria-label={t('media.optimize')}
                          onClick={() => setOptimizeIds([item.id])}
                        >
                          <Sparkles className={clsx(styles.cardIcon)} aria-hidden />
                        </Button>
                      ) : null}
                      <Button
                        size="sm"
                        variant="destructive"
                        className={clsx(styles.cardDeleteBtn)}
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
                  <TableHead className={clsx(styles.colSelect)}>
                    <input
                      type="checkbox"
                      checked={allSelected}
                      ref={(el) => {
                        if (el) el.indeterminate = someSelected && !allSelected
                      }}
                      onChange={toggleAll}
                      aria-label={t('media.selectAll')}
                    />
                  </TableHead>
                  <TableHead className={clsx(styles.colPreview)}>{t('media.preview')}</TableHead>
                  <TableHead>{t('common.name')}</TableHead>
                  <TableHead className={clsx(styles.colMime)}>{t('common.type')}</TableHead>
                  <TableHead className={clsx(styles.colSize)}>{t('media.size')}</TableHead>
                  <TableHead className={clsx(styles.colCreated)}>{t('media.created')}</TableHead>
                  <TableHead className={clsx(styles.colActions)}>{t('common.actions')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {items.map((item) => (
                  <TableRow key={item.id}>
                    <TableCell>
                      <input
                        type="checkbox"
                        checked={selectedIds.includes(item.id)}
                        onChange={() => toggleOne(item.id)}
                        aria-label={t('media.selectItem', { name: item.originalName })}
                      />
                    </TableCell>
                    <TableCell>
                      <div className={clsx(styles.thumbSm)}>
                        {item.mime.startsWith('image/') ? (
                          <img src={item.url} alt="" className={clsx(styles.thumbImg)} />
                        ) : (
                          <span className={clsx(styles.fileLabel)}>file</span>
                        )}
                      </div>
                    </TableCell>
                    <TableCell>
                      <div className={clsx(styles.nameCell)}>
                        <p className={clsx(styles.nameText)} title={item.originalName}>
                          {item.originalName}
                        </p>
                        <p className={clsx(styles.idText)}>#{item.id}</p>
                      </div>
                    </TableCell>
                    <TableCell className={clsx(styles.colMime)}>{item.mime}</TableCell>
                    <TableCell className={clsx(styles.colSize)}>{formatSize(item.size)}</TableCell>
                    <TableCell className={clsx(styles.colCreated)}>{item.createdAt}</TableCell>
                    <TableCell className={clsx(styles.colActions)}>
                      <div className={clsx(styles.rowActions)}>
                        <a
                          href={item.url}
                          target="_blank"
                          rel="noreferrer"
                          className={clsx(styles.openLinkTable)}
                        >
                          {t('common.open')}
                        </a>
                        {canOptimize(item) ? (
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setOptimizeIds([item.id])}
                          >
                            {t('media.optimize')}
                          </Button>
                        ) : null}
                        <Button size="sm" variant="destructive" onClick={() => confirmDelete(item)}>
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
            <div className={clsx(styles.pagination)}>
              <span>{t('common.pageOf', { page: meta.page, totalPages: meta.totalPages })}</span>
              <div className={clsx(styles.paginationActions)}>
                <Button
                  size="sm"
                  variant="outline"
                  disabled={page <= 1}
                  onClick={() => goToPage(Math.max(1, page - 1))}
                >
                  {t('common.prev')}
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  disabled={page >= meta.totalPages}
                  onClick={() => goToPage(page + 1)}
                >
                  {t('common.next')}
                </Button>
              </div>
            </div>
          ) : null}
        </CardContent>
      </Card>

      {optimizeIds ? (
        <OptimizeImageDialog
          open
          mediaIds={optimizeIds}
          onOpenChange={(next) => {
            if (!next) setOptimizeIds(null)
          }}
          onDone={() => {
            void queryClient.invalidateQueries({ queryKey: ['media'] })
          }}
        />
      ) : null}
    </div>
  )
}
