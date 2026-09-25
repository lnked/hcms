import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { MessageSquare } from 'lucide-react'
import { useState } from 'react'
import { FieldError } from '@/components/FieldError'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Form } from '@/components/ui/form'
import { Textarea } from '@/components/ui/textarea'
import { useAuthMe } from '@/hooks/useAcl'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import { apiFieldErrors, clearFieldError, hasFieldError, type FieldErrors } from '@/lib/formErrors'
import styles from './EntryCommentsPanel.module.css'

interface CommentUser {
  id: number
  name: string
  email: string
}

interface EntryComment {
  id: number
  resourceId: number
  entryId: number
  userId: number
  body: string
  createdAt: string | null
  user: CommentUser | null
}

interface EntryCommentsPanelProps {
  resourceId: number
  entryId: number
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function EntryCommentsPanel({
  resourceId,
  entryId,
  open,
  onOpenChange,
}: EntryCommentsPanelProps) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const me = useAuthMe()
  const [body, setBody] = useState('')
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({})

  const query = useQuery({
    queryKey: ['entry-comments', resourceId, entryId],
    enabled: open && entryId > 0,
    queryFn: () =>
      api<EntryComment[]>(`/admin/api/resources/${resourceId}/entries/${entryId}/comments`),
  })

  const create = useMutation({
    mutationFn: () =>
      api<EntryComment>(`/admin/api/resources/${resourceId}/entries/${entryId}/comments`, {
        method: 'POST',
        body: JSON.stringify({ body }),
      }),
    onSuccess: () => {
      setBody('')
      setFieldErrors({})
      void queryClient.invalidateQueries({ queryKey: ['entry-comments', resourceId, entryId] })
    },
    onError: (err) => setFieldErrors(apiFieldErrors(err)),
  })

  const remove = useMutation({
    mutationFn: (commentId: number) =>
      api(`/admin/api/resources/${resourceId}/entries/${entryId}/comments/${commentId}`, {
        method: 'DELETE',
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['entry-comments', resourceId, entryId] })
    },
  })

  const comments = query.data ?? []
  const myId = me.data?.id ?? null
  const role = me.data?.role ?? ''
  const canModerate = role === 'owner' || role === 'admin'

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className={styles.dialog}>
        <DialogHeader>
          <DialogTitle className={styles.title}>
            <MessageSquare className={styles.icon} />
            {t('entries.commentsTitle')}
          </DialogTitle>
          <DialogDescription>{t('entries.commentsHint')}</DialogDescription>
        </DialogHeader>

        {query.isLoading ? <p className={styles.muted}>{t('common.loading')}</p> : null}
        {query.isError ? (
          <p className={styles.error}>
            {query.error instanceof Error ? query.error.message : t('common.error')}
          </p>
        ) : null}

        <ul className={styles.list}>
          {comments.map((c) => {
            const canDelete = canModerate || (myId !== null && c.userId === myId)
            return (
              <li key={c.id} className={styles.item}>
                <div className={styles.itemHeader}>
                  <span className={styles.author}>
                    {c.user?.name || c.user?.email || `#${c.userId}`}
                  </span>
                  {c.createdAt ? <span className={styles.meta}>{c.createdAt}</span> : null}
                </div>
                <p className={styles.body}>{c.body}</p>
                {canDelete ? (
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={remove.isPending}
                    onClick={() => {
                      if (confirm(t('entries.commentDeleteConfirm'))) remove.mutate(c.id)
                    }}
                  >
                    {t('common.delete')}
                  </Button>
                ) : null}
              </li>
            )
          })}
        </ul>
        {!query.isLoading && comments.length === 0 ? (
          <p className={styles.muted}>{t('entries.commentsEmpty')}</p>
        ) : null}

        <Form
          className={styles.form}
          onSubmit={() => {
            create.mutate()
          }}
        >
          <Textarea
            rows={3}
            value={body}
            aria-invalid={hasFieldError(fieldErrors, 'body') || undefined}
            placeholder={t('entries.commentPlaceholder')}
            onChange={(e) => {
              setBody(e.target.value)
              setFieldErrors((prev) => clearFieldError(prev, 'body'))
            }}
          />
          <FieldError messages={fieldErrors.body} />
          <Button type="submit" disabled={create.isPending || body.trim() === ''}>
            {create.isPending ? t('common.saving') : t('entries.commentSubmit')}
          </Button>
        </Form>
      </DialogContent>
    </Dialog>
  )
}
