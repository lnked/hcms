import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { History } from 'lucide-react'
import { useState } from 'react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'

interface RevisionDiff {
  [key: string]: { from: unknown; to: unknown }
}

interface Revision {
  id: number
  resourceId: number
  entryId: number
  data: Record<string, unknown>
  diff: RevisionDiff | null
  actorUserId: number | null
  createdAt: string
}

interface EntryRevisionsPanelProps {
  resourceId: number
  entryId: number
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function EntryRevisionsPanel({
  resourceId,
  entryId,
  open,
  onOpenChange,
}: EntryRevisionsPanelProps) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [selected, setSelected] = useState<Revision | null>(null)

  const query = useQuery({
    queryKey: ['entry-revisions', resourceId, entryId],
    enabled: open && entryId > 0,
    queryFn: () =>
      api<Revision[]>(`/admin/api/resources/${resourceId}/entries/${entryId}/revisions`),
  })

  const restore = useMutation({
    mutationFn: (revId: number) =>
      api(`/admin/api/resources/${resourceId}/entries/${entryId}/revisions/${revId}/restore`, {
        method: 'POST',
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['resource-entries', resourceId] })
      await queryClient.invalidateQueries({ queryKey: ['entry-revisions', resourceId, entryId] })
      onOpenChange(false)
    },
  })

  const revisions = query.data ?? []

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <History className="size-4" />
            {t('entries.revisionsTitle')}
          </DialogTitle>
          <DialogDescription>{t('entries.revisionsHint')}</DialogDescription>
        </DialogHeader>

        {query.isLoading ? (
          <p className="text-sm text-muted-foreground">{t('common.loading')}</p>
        ) : query.isError ? (
          <p className="text-sm text-destructive">{t('common.loadError')}</p>
        ) : revisions.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t('entries.revisionsEmpty')}</p>
        ) : (
          <ul className="space-y-3">
            {revisions.map((rev) => (
              <li key={rev.id} className="rounded-md border p-3 text-sm">
                <div className="flex items-center justify-between gap-2">
                  <button
                    type="button"
                    className="text-left font-medium hover:underline"
                    onClick={() => setSelected(selected?.id === rev.id ? null : rev)}
                  >
                    #{rev.id} · {rev.createdAt}
                  </button>
                  <Button
                    size="sm"
                    variant="outline"
                    disabled={restore.isPending}
                    onClick={() => {
                      if (window.confirm(t('entries.revisionsRestoreConfirm'))) {
                        restore.mutate(rev.id)
                      }
                    }}
                  >
                    {t('entries.revisionsRestore')}
                  </Button>
                </div>
                {selected?.id === rev.id ? (
                  <div className="mt-2 space-y-2">
                    {rev.diff && Object.keys(rev.diff).length > 0 ? (
                      <div className="space-y-1 font-mono text-xs">
                        {Object.entries(rev.diff).map(([key, change]) => (
                          <div key={key}>
                            <span className="text-muted-foreground">{key}:</span>{' '}
                            <span className="text-destructive">{formatVal(change.from)}</span>
                            {' → '}
                            <span className="text-emerald-600 dark:text-emerald-400">
                              {formatVal(change.to)}
                            </span>
                          </div>
                        ))}
                      </div>
                    ) : (
                      <pre className="overflow-x-auto rounded bg-muted p-2 text-xs">
                        {JSON.stringify(rev.data, null, 2)}
                      </pre>
                    )}
                  </div>
                ) : null}
              </li>
            ))}
          </ul>
        )}
      </DialogContent>
    </Dialog>
  )
}

function formatVal(value: unknown): string {
  if (value === null || value === undefined) return 'null'
  if (typeof value === 'string') return JSON.stringify(value)
  return JSON.stringify(value)
}
