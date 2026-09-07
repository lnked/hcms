import { useMemo, useState } from 'react'
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

const WEBHOOK_EVENTS = [
  'entry.created',
  'entry.updated',
  'entry.deleted',
  'resource.published',
] as const

type WebhookEvent = (typeof WEBHOOK_EVENTS)[number]

interface Webhook {
  id: number
  name: string
  url: string
  secret: string
  events: string[]
  resourceId: number | null
  status: 'active' | 'disabled'
  createdAt: string
  updatedAt: string
}

interface WebhookDelivery {
  id: number
  webhookId: number
  event: string
  payload: Record<string, unknown>
  responseCode: number | null
  durationMs: number | null
  attempt: number
  status: 'pending' | 'success' | 'failed'
  errorMessage: string | null
  createdAt: string
}

function randomSecret(): string {
  const bytes = new Uint8Array(32)
  crypto.getRandomValues(bytes)
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
}

export function WebhooksPage() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [name, setName] = useState('')
  const [url, setUrl] = useState('')
  const [secret, setSecret] = useState('')
  const [events, setEvents] = useState<WebhookEvent[]>(['entry.created'])
  const [resourceId, setResourceId] = useState<number | null>(null)
  const [status, setStatus] = useState<'active' | 'disabled'>('active')
  const [error, setError] = useState<string | null>(null)
  const [testResult, setTestResult] = useState<string | null>(null)

  const isEdit = editingId !== null

  const webhooks = useQuery({
    queryKey: ['webhooks'],
    queryFn: () => api<Webhook[]>('/admin/api/webhooks'),
  })

  const resources = useQuery({
    queryKey: ['resources'],
    queryFn: () => api<Resource[]>('/admin/api/resources'),
  })

  const deliveries = useQuery({
    queryKey: ['webhooks', selectedId, 'deliveries'],
    queryFn: () => api<WebhookDelivery[]>(`/admin/api/webhooks/${selectedId}/deliveries`),
    enabled: selectedId !== null,
  })

  const resourceLabel = useMemo(() => {
    const map = new Map<number, string>()
    for (const r of resources.data ?? []) map.set(r.id, r.label)
    return map
  }, [resources.data])

  const create = useMutation({
    mutationFn: () =>
      api<Webhook>('/admin/api/webhooks', {
        method: 'POST',
        body: JSON.stringify({
          name,
          url,
          secret: secret.trim() || undefined,
          events,
          resourceId,
          status,
        }),
      }),
    onSuccess: (data) => {
      setError(null)
      setOpen(false)
      resetForm()
      setSelectedId(data.id)
      void queryClient.invalidateQueries({ queryKey: ['webhooks'] })
    },
    onError: (err) => setError(err instanceof Error ? err.message : t('common.createFailed')),
  })

  const update = useMutation({
    mutationFn: (id: number) =>
      api<Webhook>(`/admin/api/webhooks/${id}`, {
        method: 'PATCH',
        body: JSON.stringify({
          name,
          url,
          ...(secret.trim() ? { secret: secret.trim() } : {}),
          events,
          resourceId,
          status,
        }),
      }),
    onSuccess: () => {
      setError(null)
      setOpen(false)
      resetForm()
      void queryClient.invalidateQueries({ queryKey: ['webhooks'] })
    },
    onError: (err) => setError(err instanceof Error ? err.message : t('common.saveFailed')),
  })

  const remove = useMutation({
    mutationFn: (id: number) => api<void>(`/admin/api/webhooks/${id}`, { method: 'DELETE' }),
    onSuccess: (_data, id) => {
      if (selectedId === id) setSelectedId(null)
      void queryClient.invalidateQueries({ queryKey: ['webhooks'] })
    },
  })

  const toggleStatus = useMutation({
    mutationFn: (hook: Webhook) =>
      api<Webhook>(`/admin/api/webhooks/${hook.id}`, {
        method: 'PATCH',
        body: JSON.stringify({
          status: hook.status === 'active' ? 'disabled' : 'active',
        }),
      }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['webhooks'] }),
  })

  const test = useMutation({
    mutationFn: (id: number) =>
      api<WebhookDelivery>(`/admin/api/webhooks/${id}/test`, {
        method: 'POST',
        body: '{}',
      }),
    onSuccess: (data, id) => {
      setTestResult(
        data.status === 'success'
          ? t('webhooks.testOk', { code: String(data.responseCode ?? '—') })
          : t('webhooks.testFail', {
              code: String(data.responseCode ?? '—'),
              error: data.errorMessage ?? '',
            }),
      )
      void queryClient.invalidateQueries({ queryKey: ['webhooks', id, 'deliveries'] })
    },
    onError: (err) =>
      setTestResult(err instanceof Error ? err.message : t('webhooks.testFailGeneric')),
  })

  function resetForm() {
    setEditingId(null)
    setName('')
    setUrl('')
    setSecret('')
    setEvents(['entry.created'])
    setResourceId(null)
    setStatus('active')
    setError(null)
  }

  function openCreate() {
    resetForm()
    setSecret(randomSecret())
    setOpen(true)
  }

  function openEdit(hook: Webhook) {
    setEditingId(hook.id)
    setName(hook.name)
    setUrl(hook.url)
    setSecret('')
    setEvents(
      hook.events.filter((e): e is WebhookEvent =>
        (WEBHOOK_EVENTS as readonly string[]).includes(e),
      ),
    )
    setResourceId(hook.resourceId)
    setStatus(hook.status)
    setError(null)
    setOpen(true)
  }

  function toggleEvent(event: WebhookEvent) {
    setEvents((prev) => (prev.includes(event) ? prev.filter((e) => e !== event) : [...prev, event]))
  }

  const busy = create.isPending || update.isPending

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">{t('webhooks.title')}</h1>
          <p className="text-sm text-muted-foreground">{t('webhooks.subtitle')}</p>
        </div>
        <Button onClick={openCreate}>{t('webhooks.create')}</Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>{t('webhooks.listTitle')}</CardTitle>
          <CardDescription>{t('webhooks.listHint')}</CardDescription>
        </CardHeader>
        <CardContent>
          {webhooks.isLoading ? (
            <TableSkeleton columns={5} rows={5} />
          ) : (webhooks.data ?? []).length === 0 ? (
            <EmptyState title={t('webhooks.empty')} />
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('common.name')}</TableHead>
                  <TableHead>{t('webhooks.url')}</TableHead>
                  <TableHead>{t('webhooks.events')}</TableHead>
                  <TableHead>{t('common.status')}</TableHead>
                  <TableHead className="text-right">{t('common.actions')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {(webhooks.data ?? []).map((hook) => (
                  <TableRow
                    key={hook.id}
                    className={selectedId === hook.id ? 'bg-muted/40' : undefined}
                  >
                    <TableCell>
                      <button
                        type="button"
                        className="text-left font-medium hover:underline"
                        onClick={() => {
                          setSelectedId(hook.id)
                          setTestResult(null)
                        }}
                      >
                        {hook.name}
                      </button>
                      <div className="text-xs text-muted-foreground">
                        {hook.resourceId == null
                          ? t('webhooks.allResources')
                          : (resourceLabel.get(hook.resourceId) ?? `#${hook.resourceId}`)}
                      </div>
                    </TableCell>
                    <TableCell className="max-w-[220px] truncate font-mono text-xs">
                      {hook.url}
                    </TableCell>
                    <TableCell className="text-xs text-muted-foreground">
                      {hook.events.join(', ')}
                    </TableCell>
                    <TableCell>
                      {hook.status === 'active' ? (
                        <Badge>{t('webhooks.active')}</Badge>
                      ) : (
                        <Badge variant="destructive">{t('webhooks.disabled')}</Badge>
                      )}
                    </TableCell>
                    <TableCell className="text-right">
                      <div className="flex flex-wrap justify-end gap-2">
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={test.isPending}
                          onClick={() => {
                            setSelectedId(hook.id)
                            setTestResult(null)
                            test.mutate(hook.id)
                          }}
                        >
                          {t('webhooks.test')}
                        </Button>
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={toggleStatus.isPending}
                          onClick={() => toggleStatus.mutate(hook)}
                        >
                          {hook.status === 'active' ? t('webhooks.disable') : t('webhooks.enable')}
                        </Button>
                        <Button size="sm" variant="outline" onClick={() => openEdit(hook)}>
                          {t('common.edit')}
                        </Button>
                        <Button
                          size="sm"
                          variant="destructive"
                          disabled={remove.isPending}
                          onClick={() => {
                            if (confirm(t('webhooks.deleteConfirm', { name: hook.name }))) {
                              remove.mutate(hook.id)
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
          {testResult ? <p className="mt-3 text-sm text-muted-foreground">{testResult}</p> : null}
        </CardContent>
      </Card>

      {selectedId !== null ? (
        <Card>
          <CardHeader>
            <CardTitle>{t('webhooks.deliveriesTitle')}</CardTitle>
            <CardDescription>{t('webhooks.deliveriesHint')}</CardDescription>
          </CardHeader>
          <CardContent>
            {deliveries.isLoading ? (
              <TableSkeleton columns={6} rows={4} />
            ) : (deliveries.data ?? []).length === 0 ? (
              <EmptyState title={t('webhooks.deliveriesEmpty')} />
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('webhooks.event')}</TableHead>
                    <TableHead>{t('common.status')}</TableHead>
                    <TableHead>{t('webhooks.attempt')}</TableHead>
                    <TableHead>{t('webhooks.responseCode')}</TableHead>
                    <TableHead>{t('webhooks.duration')}</TableHead>
                    <TableHead>{t('webhooks.createdAt')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {(deliveries.data ?? []).map((d) => (
                    <TableRow key={d.id}>
                      <TableCell className="font-mono text-xs">{d.event}</TableCell>
                      <TableCell>
                        {d.status === 'success' ? (
                          <Badge>{d.status}</Badge>
                        ) : d.status === 'pending' ? (
                          <Badge variant="secondary">{d.status}</Badge>
                        ) : (
                          <Badge variant="destructive">{d.status}</Badge>
                        )}
                      </TableCell>
                      <TableCell>{d.attempt}</TableCell>
                      <TableCell>{d.responseCode ?? '—'}</TableCell>
                      <TableCell>{d.durationMs != null ? `${d.durationMs}ms` : '—'}</TableCell>
                      <TableCell className="text-xs text-muted-foreground">{d.createdAt}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </CardContent>
        </Card>
      ) : null}

      <Dialog
        open={open}
        onOpenChange={(next) => {
          setOpen(next)
          if (!next) resetForm()
        }}
      >
        <DialogContent className="max-w-xl">
          <DialogHeader>
            <DialogTitle>
              {isEdit ? t('webhooks.editTitle') : t('webhooks.createTitle')}
            </DialogTitle>
            <DialogDescription>
              {isEdit ? t('webhooks.editHint') : t('webhooks.createHint')}
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4">
            <div className="space-y-1.5">
              <Label htmlFor="webhook-name">{t('common.name')}</Label>
              <Input
                id="webhook-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder={t('webhooks.placeholderName')}
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="webhook-url">{t('webhooks.url')}</Label>
              <Input
                id="webhook-url"
                value={url}
                onChange={(e) => setUrl(e.target.value)}
                placeholder="https://example.com/hooks/hcms"
              />
            </div>
            <div className="space-y-1.5">
              <div className="flex items-center justify-between">
                <Label htmlFor="webhook-secret">{t('webhooks.secret')}</Label>
                {!isEdit ? (
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => setSecret(randomSecret())}
                  >
                    {t('webhooks.regenerateSecret')}
                  </Button>
                ) : null}
              </div>
              <Input
                id="webhook-secret"
                value={secret}
                onChange={(e) => setSecret(e.target.value)}
                placeholder={isEdit ? t('webhooks.secretKeep') : undefined}
              />
            </div>
            <div className="space-y-1.5">
              <Label>{t('webhooks.events')}</Label>
              <div className="space-y-2 rounded-md border p-3">
                {WEBHOOK_EVENTS.map((event) => (
                  <label key={event} className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={events.includes(event)}
                      onChange={() => toggleEvent(event)}
                    />
                    <span className="font-mono text-xs">{event}</span>
                  </label>
                ))}
              </div>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="webhook-resource">{t('webhooks.resource')}</Label>
              <Select
                id="webhook-resource"
                value={resourceId ?? ''}
                onChange={(e) => {
                  const value = e.target.value
                  setResourceId(value === '' ? null : Number(value))
                }}
              >
                <option value="">{t('webhooks.allResources')}</option>
                {(resources.data ?? []).map((r) => (
                  <option key={r.id} value={r.id}>
                    {r.label} ({r.slug})
                  </option>
                ))}
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="webhook-status">{t('common.status')}</Label>
              <Select
                id="webhook-status"
                value={status}
                onChange={(e) => setStatus(e.target.value === 'disabled' ? 'disabled' : 'active')}
              >
                <option value="active">{t('webhooks.active')}</option>
                <option value="disabled">{t('webhooks.disabled')}</option>
              </Select>
            </div>

            {error ? <p className="text-sm text-destructive">{error}</p> : null}
            <div className="flex justify-end gap-2">
              <Button variant="outline" onClick={() => setOpen(false)}>
                {t('common.cancel')}
              </Button>
              {isEdit ? (
                <Button
                  disabled={!name.trim() || !url.trim() || events.length === 0 || busy}
                  onClick={() => {
                    if (editingId !== null) update.mutate(editingId)
                  }}
                >
                  {update.isPending ? t('common.saving') : t('common.save')}
                </Button>
              ) : (
                <Button
                  disabled={!name.trim() || !url.trim() || events.length === 0 || busy}
                  onClick={() => create.mutate()}
                >
                  {create.isPending ? t('common.creating') : t('common.create')}
                </Button>
              )}
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
