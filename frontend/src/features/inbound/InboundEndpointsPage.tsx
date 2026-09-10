import { useMemo, useState } from 'react'
import { clsx } from 'clsx'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { TableSkeleton } from '@/components/skeletons'
import { EmptyState } from '@/components/EmptyState'
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
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import type { Resource } from '@/types/resource'
import styles from '@/features/webhooks/WebhooksPage.module.css'

interface InboundEndpoint {
  id: number
  slug: string
  label: string
  targetUrl: string
  secret: string
  persistResourceId: number | null
  fieldMap: Record<string, string> | null
  enabled: boolean
  timeoutMs: number
  onFailure: 'reject' | 'continue'
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

export function InboundEndpointsPage() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [label, setLabel] = useState('')
  const [slug, setSlug] = useState('')
  const [targetUrl, setTargetUrl] = useState('')
  const [secret, setSecret] = useState('')
  const [persistResourceId, setPersistResourceId] = useState<number | null>(null)
  const [fieldMapText, setFieldMapText] = useState('')
  const [timeoutMs, setTimeoutMs] = useState(5000)
  const [onFailure, setOnFailure] = useState<'reject' | 'continue'>('reject')
  const [enabled, setEnabled] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [testResult, setTestResult] = useState<string | null>(null)

  const isEdit = editingId !== null

  const endpoints = useQuery({
    queryKey: ['inbound-endpoints'],
    queryFn: () => api<InboundEndpoint[]>('/admin/api/inbound-endpoints'),
  })

  const resources = useQuery({
    queryKey: ['resources'],
    queryFn: () => api<Resource[]>('/admin/api/resources'),
  })

  const deliveries = useQuery({
    queryKey: ['inbound-endpoints', selectedId, 'deliveries'],
    queryFn: () => api<HookDelivery[]>(`/admin/api/inbound-endpoints/${selectedId}/deliveries`),
    enabled: selectedId !== null,
  })

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['inbound-endpoints'] })
  }

  const parseFieldMap = (): Record<string, string> | null => {
    const trimmed = fieldMapText.trim()
    if (trimmed === '') return null
    const parsed: unknown = JSON.parse(trimmed)
    if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
      throw new Error(t('inbound.fieldMapInvalid'))
    }
    return parsed as Record<string, string>
  }

  const bodyPayload = () => {
    const fieldMap = parseFieldMap()
    return {
      label,
      slug,
      targetUrl,
      ...(secret ? { secret } : {}),
      persistResourceId,
      fieldMap,
      timeoutMs,
      onFailure,
      enabled,
    }
  }

  const createMutation = useMutation({
    mutationFn: () =>
      api<InboundEndpoint>('/admin/api/inbound-endpoints', {
        method: 'POST',
        body: JSON.stringify(bodyPayload()),
      }),
    onSuccess: () => {
      setOpen(false)
      invalidate()
    },
    onError: (err: Error) => setError(err.message),
  })

  const updateMutation = useMutation({
    mutationFn: (id: number) =>
      api<InboundEndpoint>(`/admin/api/inbound-endpoints/${id}`, {
        method: 'PATCH',
        body: JSON.stringify(bodyPayload()),
      }),
    onSuccess: () => {
      setOpen(false)
      invalidate()
    },
    onError: (err: Error) => setError(err.message),
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) =>
      api<void>(`/admin/api/inbound-endpoints/${id}`, { method: 'DELETE' }),
    onSuccess: invalidate,
  })

  const toggleMutation = useMutation({
    mutationFn: (ep: InboundEndpoint) =>
      api<InboundEndpoint>(`/admin/api/inbound-endpoints/${ep.id}`, {
        method: 'PATCH',
        body: JSON.stringify({ enabled: !ep.enabled }),
      }),
    onSuccess: invalidate,
  })

  const testMutation = useMutation({
    mutationFn: (id: number) =>
      api<HookDelivery>(`/admin/api/inbound-endpoints/${id}/test`, { method: 'POST' }),
    onSuccess: (data, id) => {
      setTestResult(
        data.status === 'success'
          ? t('inbound.testOk', { code: String(data.responseCode ?? '—') })
          : t('inbound.testFail', {
              code: String(data.responseCode ?? '—'),
              error: data.errorMessage ?? '',
            }),
      )
      void queryClient.invalidateQueries({ queryKey: ['inbound-endpoints', id, 'deliveries'] })
    },
    onError: (err: Error) => setTestResult(err.message || t('inbound.testFailGeneric')),
  })

  const openCreate = () => {
    setEditingId(null)
    setLabel('')
    setSlug('')
    setTargetUrl('')
    setSecret(randomSecret())
    setPersistResourceId(null)
    setFieldMapText('')
    setTimeoutMs(5000)
    setOnFailure('reject')
    setEnabled(true)
    setError(null)
    setOpen(true)
  }

  const openEdit = (ep: InboundEndpoint) => {
    setEditingId(ep.id)
    setLabel(ep.label)
    setSlug(ep.slug)
    setTargetUrl(ep.targetUrl)
    setSecret('')
    setPersistResourceId(ep.persistResourceId)
    setFieldMapText(ep.fieldMap ? JSON.stringify(ep.fieldMap, null, 2) : '')
    setTimeoutMs(ep.timeoutMs)
    setOnFailure(ep.onFailure)
    setEnabled(ep.enabled)
    setError(null)
    setOpen(true)
  }

  const rows = useMemo(() => endpoints.data ?? [], [endpoints.data])
  const resourceLabel = (id: number | null) => {
    if (id === null) return t('inbound.noPersist')
    const r = (resources.data ?? []).find((item) => item.id === id)
    return r ? `${r.label} (${r.slug})` : `#${id}`
  }

  const save = () => {
    try {
      if (isEdit && editingId !== null) updateMutation.mutate(editingId)
      else createMutation.mutate()
    } catch (err) {
      setError(err instanceof Error ? err.message : t('inbound.fieldMapInvalid'))
    }
  }

  return (
    <div className={clsx(styles.root)}>
      <div className={clsx(styles.pageHeader)}>
        <div>
          <h1 className={clsx(styles.title)}>{t('inbound.title')}</h1>
          <p className={clsx(styles.subtitle)}>{t('inbound.subtitle')}</p>
        </div>
        <Button onClick={openCreate}>{t('inbound.create')}</Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>{t('inbound.listTitle')}</CardTitle>
          <CardDescription>{t('inbound.listHint')}</CardDescription>
        </CardHeader>
        <CardContent>
          {endpoints.isLoading ? (
            <TableSkeleton rows={4} />
          ) : rows.length === 0 ? (
            <EmptyState title={t('inbound.empty')} />
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('common.name')}</TableHead>
                  <TableHead>{t('inbound.slug')}</TableHead>
                  <TableHead>{t('inbound.targetUrl')}</TableHead>
                  <TableHead>{t('inbound.persist')}</TableHead>
                  <TableHead>{t('common.status')}</TableHead>
                  <TableHead className={styles.alignRight}>{t('common.actions')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((ep) => (
                  <TableRow
                    key={ep.id}
                    className={clsx(selectedId === ep.id && styles.rowSelected)}
                  >
                    <TableCell>
                      <button
                        type="button"
                        className={styles.nameBtn}
                        onClick={() => {
                          setSelectedId(ep.id)
                          setTestResult(null)
                        }}
                      >
                        {ep.label}
                      </button>
                    </TableCell>
                    <TableCell className={styles.monoXs}>/api/inbound/{ep.slug}</TableCell>
                    <TableCell className={styles.urlCell}>{ep.targetUrl}</TableCell>
                    <TableCell>{resourceLabel(ep.persistResourceId)}</TableCell>
                    <TableCell>
                      {ep.enabled ? (
                        <Badge>{t('inbound.enabled')}</Badge>
                      ) : (
                        <Badge variant="destructive">{t('inbound.disabled')}</Badge>
                      )}
                    </TableCell>
                    <TableCell className={styles.alignRight}>
                      <div className={styles.rowActions}>
                        <Button variant="outline" size="sm" onClick={() => openEdit(ep)}>
                          {t('common.edit')}
                        </Button>
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => testMutation.mutate(ep.id)}
                        >
                          {t('inbound.test')}
                        </Button>
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => toggleMutation.mutate(ep)}
                        >
                          {ep.enabled ? t('inbound.disable') : t('inbound.enable')}
                        </Button>
                        <Button
                          variant="destructive"
                          size="sm"
                          onClick={() => {
                            if (confirm(t('inbound.deleteConfirm', { name: ep.label }))) {
                              deleteMutation.mutate(ep.id)
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
          {testResult ? <p className={styles.testResult}>{testResult}</p> : null}
        </CardContent>
      </Card>

      {selectedId !== null ? (
        <Card>
          <CardHeader>
            <CardTitle>{t('inbound.deliveriesTitle')}</CardTitle>
            <CardDescription>{t('inbound.deliveriesHint')}</CardDescription>
          </CardHeader>
          <CardContent>
            {deliveries.isLoading ? (
              <TableSkeleton rows={3} />
            ) : (deliveries.data ?? []).length === 0 ? (
              <EmptyState title={t('inbound.deliveriesEmpty')} />
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('common.status')}</TableHead>
                    <TableHead>{t('inbound.responseCode')}</TableHead>
                    <TableHead>{t('inbound.duration')}</TableHead>
                    <TableHead>{t('inbound.createdAt')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {(deliveries.data ?? []).map((d) => (
                    <TableRow key={d.id}>
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
            <DialogTitle>{isEdit ? t('inbound.editTitle') : t('inbound.createTitle')}</DialogTitle>
            <DialogDescription>
              {isEdit ? t('inbound.editHint') : t('inbound.createHint')}
            </DialogDescription>
          </DialogHeader>
          <div className={clsx(styles.stackMd)}>
            <div className={clsx(styles.stackXs)}>
              <Label htmlFor="inbound-label">{t('common.name')}</Label>
              <Input id="inbound-label" value={label} onChange={(e) => setLabel(e.target.value)} />
            </div>
            <div className={clsx(styles.stackXs)}>
              <Label htmlFor="inbound-slug">{t('inbound.slug')}</Label>
              <Input id="inbound-slug" value={slug} onChange={(e) => setSlug(e.target.value)} />
            </div>
            <div className={clsx(styles.stackXs)}>
              <Label htmlFor="inbound-url">{t('inbound.targetUrl')}</Label>
              <Input
                id="inbound-url"
                value={targetUrl}
                onChange={(e) => setTargetUrl(e.target.value)}
                placeholder="https://hooks.example.com/contact"
              />
            </div>
            <div className={clsx(styles.stackXs)}>
              <div className={clsx(styles.fieldHeader)}>
                <Label htmlFor="inbound-secret">{t('inbound.secret')}</Label>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  onClick={() => setSecret(randomSecret())}
                >
                  {t('inbound.regenerateSecret')}
                </Button>
              </div>
              <Input
                id="inbound-secret"
                value={secret}
                onChange={(e) => setSecret(e.target.value)}
                placeholder={isEdit ? t('inbound.secretKeep') : undefined}
              />
            </div>
            <div className={clsx(styles.stackXs)}>
              <Label htmlFor="inbound-persist">{t('inbound.persist')}</Label>
              <Select
                id="inbound-persist"
                value={persistResourceId ?? ''}
                onChange={(e) => {
                  const value = e.target.value
                  setPersistResourceId(value === '' ? null : Number(value))
                }}
              >
                <option value="">{t('inbound.noPersist')}</option>
                {(resources.data ?? []).map((r) => (
                  <option key={r.id} value={r.id}>
                    {r.label} ({r.slug})
                  </option>
                ))}
              </Select>
            </div>
            <div className={clsx(styles.stackXs)}>
              <Label htmlFor="inbound-fieldmap">{t('inbound.fieldMap')}</Label>
              <Input
                id="inbound-fieldmap"
                value={fieldMapText}
                onChange={(e) => setFieldMapText(e.target.value)}
                placeholder='{"email":"email","name":"full_name"}'
              />
            </div>
            <div className={clsx(styles.stackXs)}>
              <Label htmlFor="inbound-timeout">{t('inbound.timeoutMs')}</Label>
              <Input
                id="inbound-timeout"
                type="number"
                value={timeoutMs}
                onChange={(e) => setTimeoutMs(Number(e.target.value) || 5000)}
              />
            </div>
            <div className={clsx(styles.stackXs)}>
              <Label htmlFor="inbound-on-failure">{t('inbound.onFailure')}</Label>
              <Select
                id="inbound-on-failure"
                value={onFailure}
                onChange={(e) => setOnFailure(e.target.value as 'reject' | 'continue')}
              >
                <option value="reject">reject</option>
                <option value="continue">continue</option>
              </Select>
            </div>
            <div className={clsx(styles.stackXs)}>
              <Label htmlFor="inbound-enabled">{t('common.status')}</Label>
              <Select
                id="inbound-enabled"
                value={enabled ? 'enabled' : 'disabled'}
                onChange={(e) => setEnabled(e.target.value === 'enabled')}
              >
                <option value="enabled">{t('inbound.enabled')}</option>
                <option value="disabled">{t('inbound.disabled')}</option>
              </Select>
            </div>
            {error ? <p className={clsx(styles.error)}>{error}</p> : null}
            <div className={clsx(styles.actions)}>
              <Button variant="outline" onClick={() => setOpen(false)}>
                {t('common.cancel')}
              </Button>
              <Button
                onClick={save}
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
