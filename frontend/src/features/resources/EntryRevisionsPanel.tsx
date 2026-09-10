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
import styles from './EntryRevisionsPanel.module.css'

interface RevisionDiff {
  [key: string]: { from: unknown; to: unknown }
}

interface ActorRef {
  id: number
  name: string
  email: string
}

interface Revision {
  id: number
  resourceId: number
  entryId: number
  data: Record<string, unknown>
  diff: RevisionDiff | null
  actorUserId: number | null
  actor: ActorRef | null
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
      await queryClient.invalidateQueries({ queryKey: ['resource-entry', resourceId, entryId] })
      await queryClient.invalidateQueries({ queryKey: ['entry-revisions', resourceId, entryId] })
      onOpenChange(false)
    },
  })

  const revisions = query.data ?? []

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className={styles.dialog}>
        <DialogHeader>
          <DialogTitle className={styles.title}>
            <History className={styles.icon} />
            {t('entries.revisionsTitle')}
          </DialogTitle>
          <DialogDescription>{t('entries.revisionsHint')}</DialogDescription>
        </DialogHeader>

        {query.isLoading ? (
          <p className={styles.muted}>{t('common.loading')}</p>
        ) : query.isError ? (
          <p className={styles.error}>{t('common.loadError')}</p>
        ) : revisions.length === 0 ? (
          <p className={styles.muted}>{t('entries.revisionsEmpty')}</p>
        ) : (
          <ul className={styles.list}>
            {revisions.map((rev) => (
              <li key={rev.id} className={styles.item}>
                <div className={styles.itemHeader}>
                  <button
                    type="button"
                    className={styles.selectBtn}
                    onClick={() => setSelected(selected?.id === rev.id ? null : rev)}
                  >
                    <span className={styles.revMeta}>
                      <span className={styles.revId}>#{rev.id}</span>
                      <span className={styles.revWhen}>{rev.createdAt}</span>
                      <span className={styles.revActor}>
                        {formatActor(rev.actor, t('entries.revisionsUnknownActor'))}
                      </span>
                    </span>
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
                  <div className={styles.detail}>
                    {rev.diff && Object.keys(rev.diff).length > 0 ? (
                      <div className={styles.diff}>
                        {Object.entries(rev.diff).map(([key, change]) => (
                          <div key={key}>
                            <span className={styles.diffKey}>{key}:</span>{' '}
                            <span className={styles.diffFrom}>{formatVal(change.from)}</span>
                            {' → '}
                            <span className={styles.diffTo}>{formatVal(change.to)}</span>
                          </div>
                        ))}
                      </div>
                    ) : (
                      <pre className={styles.snapshot}>{JSON.stringify(rev.data, null, 2)}</pre>
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

function formatActor(actor: ActorRef | null, unknownLabel: string): string {
  if (!actor) return unknownLabel
  const name = actor.name.trim()
  if (name !== '') return name
  const email = actor.email.trim()
  return email !== '' ? email : unknownLabel
}

function formatVal(value: unknown): string {
  if (value === null || value === undefined) return 'null'
  if (typeof value === 'string') return JSON.stringify(value)
  return JSON.stringify(value)
}
