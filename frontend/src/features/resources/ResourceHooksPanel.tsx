import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { clsx } from 'clsx'
import { useMemo, useState } from 'react'
import { EmptyState } from '@/components/EmptyState'
import { FieldError } from '@/components/FieldError'
import { TableSkeleton } from '@/components/skeletons'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import styles from '@/features/webhooks/WebhooksPage.module.css'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import { apiFieldErrors, type FieldErrors } from '@/lib/formErrors'
import { showSuccess } from '@/lib/toast'

type HookPhase = 'before_create' | 'after_create'

interface ResourceHook {
  id: number
  resourceId: number
  name: string
  phase: HookPhase
  url: string
  secret: string
  timeoutMs: number
  onFailure: 'reject' | 'continue'
  status: 'active' | 'disabled'
  createdAt: string
  updatedAt: string
}

interface HookDelivery {
  id: number
  hookId: number
  phase: string
  responseCode: number | null
  durationMs: number | null
  status: string
  errorMessage: string | null
  createdAt: string
}

function randomSecret(): string {
  const bytes = new Uint8Array(32)
  crypto.getRandomValues(bytes)
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
}

export function ResourceHooksPanel({ resourceId }: { resourceId: number }) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [name, setName] = useState('')
  const [phase, setPhase] = useState<HookPhase>('before_create')
  const [url, setUrl] = useState('')
  const [secret, setSecret] = useState('')
  const [timeoutMs, setTimeoutMs] = useState(3000)
  const [onFailure, setOnFailure] = useState<'reject' | 'continue'>('reject')
  const [status, setStatus] = useState<'active' | 'disabled'>('active')
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({})
  const [testResult, setTestResult] = useState<string | null>(null)

  const isEdit = editingId !== null
  const base = `/admin/api/resources/${resourceId}/hooks`

  const hooks = useQuery({
    queryKey: ['resource-hooks', resourceId],
    queryFn: () => api<ResourceHook[]>(base),
  })

  const deliveries = useQuery({
    queryKey: ['resource-hooks', resourceId, selectedId, 'deliveries'],
    queryFn: () => api<HookDelivery[]>(`${base}/${selectedId}/deliveries`),
    enabled: selectedId !== null,
  })

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['resource-hooks', resourceId] })
  }

  const createMutation = useMutation({
    mutationFn: () =>
      api<ResourceHook>(base, {
        method: 'POST',
        body: JSON.stringify({
          name,
          phase,
          url,
          secret: secret || undefined,
          timeoutMs,
          onFailure,
          status,
        }),
      }),
    onSuccess: () => {
      showSuccess(t('common.saved'))
      setOpen(false)
      invalidate()
    },
    onError: (err) => setFieldErrors(apiFieldErrors(err)),
  })

  const updateMutation = useMutation({
    mutationFn: (id: number) =>
      api<ResourceHook>(`${base}/${id}`, {
        method: 'PATCH',
        body: JSON.stringify({
          name,
          phase,
          url,
          ...(secret ? { secret } : {}),
          timeoutMs,
          onFailure,
          status,
        }),
      }),
    onSuccess: () => {
      showSuccess(t('common.saved'))
      setOpen(false)
      invalidate()
    },
    onError: (err) => setFieldErrors(apiFieldErrors(err)),
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => api<void>(`${base}/${id}`, { method: 'DELETE' }),
    onSuccess: () => {
      if (selectedId === editingId) setSelectedId(null)
      invalidate()
    },
  })

  const toggleMutation = useMutation({
    mutationFn: (hook: ResourceHook) =>
      api<ResourceHook>(`${base}/${hook.id}`, {
        method: 'PATCH',
        body: JSON.stringify({ status: hook.status === 'active' ? 'disabled' : 'active' }),
      }),
    onSuccess: invalidate,
  })

  const testMutation = useMutation({
    mutationFn: (id: number) => api<HookDelivery>(`${base}/${id}/test`, { method: 'POST' }),
    onSuccess: (data, id) => {
      setTestResult(
        data.status === 'success'
          ? t('hooks.testOk', { code: String(data.responseCode ?? '—') })
          : t('hooks.testFail', {
              code: String(data.responseCode ?? '—'),
              error: data.errorMessage ?? '',
            }),
      )
      void queryClient.invalidateQueries({
        queryKey: ['resource-hooks', resourceId, id, 'deliveries'],
      })
    },
    onError: (err: Error) => setTestResult(err.message || t('hooks.testFailGeneric')),
  })

  const openCreate = () => {
    setEditingId(null)
    setName('')
    setPhase('before_create')
    setUrl('')
    setSecret(randomSecret())
    setTimeoutMs(3000)
    setOnFailure('reject')
    setStatus('active')
    setFieldErrors({})
    setOpen(true)
  }

  const openEdit = (hook: ResourceHook) => {
    setEditingId(hook.id)
    setName(hook.name)
    setPhase(hook.phase)
    setUrl(hook.url)
    setSecret('')
    setTimeoutMs(hook.timeoutMs)
    setOnFailure(hook.onFailure)
    setStatus(hook.status)
    setFieldErrors({})
    setOpen(true)
  }

  const rows = useMemo(() => hooks.data ?? [], [hooks.data])

  return (
    <div className={clsx(styles.root)}>
      <div className={clsx(styles.pageHeader)}>
        <div>
          <h2 className={clsx(styles.title)}>{t('hooks.title')}</h2>
          <p className={clsx(styles.subtitle)}>{t('hooks.subtitle')}</p>
        </div>
        <Button onClick={openCreate}>{t('hooks.create')}</Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>{t('hooks.listTitle')}</CardTitle>
          <CardDescription>{t('hooks.listHint')}</CardDescription>
        </CardHeader>
        <CardContent>
          {hooks.isLoading ? (
            <TableSkeleton rows={4} />
          ) : rows.length === 0 ? (
            <EmptyState title={t('hooks.empty')} />
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('common.name')}</TableHead>
                  <TableHead>{t('hooks.phase')}</TableHead>
                  <TableHead>{t('hooks.url')}</TableHead>
                  <TableHead>{t('common.status')}</TableHead>
                  <TableHead className={styles.alignRight}>{t('common.actions')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((hook) => (
                  <TableRow
                    key={hook.id}
                    className={clsx(selectedId === hook.id && styles.rowSelected)}
                  >
                    <TableCell>
                      <button
                        type="button"
                        className={styles.nameBtn}
                        onClick={() => {
                          setSelectedId(hook.id)
                          setTestResult(null)
                        }}
                      >
                        {hook.name}
                      </button>
                    </TableCell>
                    <TableCell>{hook.phase}</TableCell>
                    <TableCell className={styles.mutedXs}>{hook.url}</TableCell>
                    <TableCell>
                      {hook.status === 'active' ? (
                        <Badge>{t('hooks.active')}</Badge>
                      ) : (
                        <Badge variant="destructive">{t('hooks.disabled')}</Badge>
                      )}
                    </TableCell>
                    <TableCell className={styles.alignRight}>
                      <div className={styles.rowActions}>
                        <Button variant="outline" size="sm" onClick={() => openEdit(hook)}>
                          {t('common.edit')}
                        </Button>
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => testMutation.mutate(hook.id)}
                        >
                          {t('hooks.test')}
                        </Button>
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => toggleMutation.mutate(hook)}
                        >
                          {hook.status === 'active' ? t('hooks.disable') : t('hooks.enable')}
                        </Button>
                        <Button
                          variant="destructive"
                          size="sm"
                          onClick={() => {
                            if (confirm(t('hooks.deleteConfirm', { name: hook.name }))) {
                              deleteMutation.mutate(hook.id)
                            }
                          }}
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
          {testResult ? <p className={styles.mutedXs}>{testResult}</p> : null}
        </CardContent>
      </Card>

      {selectedId !== null ? (
        <Card>
          <CardHeader>
            <CardTitle>{t('hooks.deliveriesTitle')}</CardTitle>
            <CardDescription>{t('hooks.deliveriesHint')}</CardDescription>
          </CardHeader>
          <CardContent>
            {deliveries.isLoading ? (
              <TableSkeleton rows={3} />
            ) : (deliveries.data ?? []).length === 0 ? (
              <EmptyState title={t('hooks.deliveriesEmpty')} />
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('hooks.phase')}</TableHead>
                    <TableHead>{t('common.status')}</TableHead>
                    <TableHead>{t('hooks.responseCode')}</TableHead>
                    <TableHead>{t('hooks.duration')}</TableHead>
                    <TableHead>{t('hooks.createdAt')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {(deliveries.data ?? []).map((d) => (
                    <TableRow key={d.id}>
                      <TableCell>{d.phase}</TableCell>
                      <TableCell>{d.status}</TableCell>
                      <TableCell>{d.responseCode ?? '—'}</TableCell>
                      <TableCell>{d.durationMs != null ? `${d.durationMs}ms` : '—'}</TableCell>
                      <TableCell>{d.createdAt}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </CardContent>
        </Card>
      ) : null}

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className={clsx(styles.dialogMd)}>
          <DialogHeader>
            <DialogTitle>{isEdit ? t('hooks.editTitle') : t('hooks.createTitle')}</DialogTitle>
            <DialogDescription>
              {isEdit ? t('hooks.editHint') : t('hooks.createHint')}
            </DialogDescription>
          </DialogHeader>
          <div className={clsx(styles.stackMd)}>
            <div className={clsx(styles.stackXs)}>
              <Label htmlFor="hook-name">{t('common.name')}</Label>
              <Input
                id="hook-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder={t('hooks.placeholderName')}
              />
              <FieldError messages={fieldErrors.name} />
            </div>
            <div className={clsx(styles.stackXs)}>
              <Label htmlFor="hook-phase">{t('hooks.phase')}</Label>
              <Select
                id="hook-phase"
                value={phase}
                onChange={(e) => setPhase(e.target.value as HookPhase)}
              >
                <option value="before_create">before_create</option>
                <option value="after_create">after_create</option>
              </Select>
              <FieldError messages={fieldErrors.phase} />
            </div>
            <div className={clsx(styles.stackXs)}>
              <Label htmlFor="hook-url">{t('hooks.url')}</Label>
              <Input id="hook-url" value={url} onChange={(e) => setUrl(e.target.value)} />
              <FieldError messages={fieldErrors.url} />
            </div>
            <div className={clsx(styles.stackXs)}>
              <div className={clsx(styles.fieldHeader)}>
                <Label htmlFor="hook-secret">{t('hooks.secret')}</Label>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  onClick={() => setSecret(randomSecret())}
                >
                  {t('hooks.regenerateSecret')}
                </Button>
              </div>
              <Input
                id="hook-secret"
                value={secret}
                onChange={(e) => setSecret(e.target.value)}
                placeholder={isEdit ? t('hooks.secretKeep') : undefined}
              />
              <FieldError messages={fieldErrors.secret} />
            </div>
            <div className={clsx(styles.stackXs)}>
              <Label htmlFor="hook-timeout">{t('hooks.timeoutMs')}</Label>
              <Input
                id="hook-timeout"
                type="number"
                value={timeoutMs}
                onChange={(e) => setTimeoutMs(Number(e.target.value) || 3000)}
              />
              <FieldError messages={fieldErrors.timeoutMs} />
            </div>
            <div className={clsx(styles.stackXs)}>
              <Label htmlFor="hook-on-failure">{t('hooks.onFailure')}</Label>
              <Select
                id="hook-on-failure"
                value={onFailure}
                onChange={(e) => setOnFailure(e.target.value as 'reject' | 'continue')}
              >
                <option value="reject">reject</option>
                <option value="continue">continue</option>
              </Select>
              <FieldError messages={fieldErrors.onFailure} />
            </div>
            <div className={clsx(styles.stackXs)}>
              <Label htmlFor="hook-status">{t('common.status')}</Label>
              <Select
                id="hook-status"
                value={status}
                onChange={(e) => setStatus(e.target.value as 'active' | 'disabled')}
              >
                <option value="active">{t('hooks.active')}</option>
                <option value="disabled">{t('hooks.disabled')}</option>
              </Select>
              <FieldError messages={fieldErrors.status} />
            </div>
            <div className={clsx(styles.actions)}>
              <Button variant="outline" onClick={() => setOpen(false)}>
                {t('common.cancel')}
              </Button>
              <Button
                onClick={() =>
                  isEdit && editingId !== null
                    ? updateMutation.mutate(editingId)
                    : createMutation.mutate()
                }
                disabled={createMutation.isPending || updateMutation.isPending}
              >
                {t('common.save')}
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
