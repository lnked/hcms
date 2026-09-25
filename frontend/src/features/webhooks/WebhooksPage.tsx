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
import { Form } from '@/components/ui/form'
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
import { apiFieldErrors, clearFieldError, hasFieldError, type FieldErrors } from '@/lib/formErrors'
import { showSuccess } from '@/lib/toast'
import styles from './WebhooksPage.module.css'
import type { Resource } from '@/types/resource'

const WEBHOOK_EVENTS = [
  'entry.created',
  'entry.updated',
  'entry.deleted',
  'entry.submitted',
  'entry.published',
  'entry.unpublished',
  'resource.published',
] as const

type WebhookEvent = (typeof WEBHOOK_EVENTS)[number]

const WEBHOOK_PRESETS = [
  { id: 'custom', payloadMode: 'hcms' as const, events: ['entry.created'] as WebhookEvent[] },
  {
    id: 'vercel_deploy',
    payloadMode: 'empty' as const,
    events: [
      'entry.created',
      'entry.updated',
      'entry.deleted',
      'resource.published',
    ] as WebhookEvent[],
  },
  {
    id: 'netlify_build',
    payloadMode: 'empty' as const,
    events: [
      'entry.created',
      'entry.updated',
      'entry.deleted',
      'resource.published',
    ] as WebhookEvent[],
  },
  {
    id: 'cloudflare_purge',
    payloadMode: 'surrogate_keys' as const,
    events: ['entry.updated', 'entry.deleted', 'resource.published'] as WebhookEvent[],
  },
  {
    id: 'fastly_purge',
    payloadMode: 'surrogate_keys' as const,
    events: ['entry.updated', 'entry.deleted', 'resource.published'] as WebhookEvent[],
  },
] as const

type WebhookPresetId = (typeof WEBHOOK_PRESETS)[number]['id']

interface Webhook {
  id: number
  name: string
  url: string
  secret: string
  events: string[]
  resourceId: number | null
  status: 'active' | 'disabled'
  preset: string | null
  payloadMode: 'hcms' | 'empty' | 'surrogate_keys'
  headers: Record<string, string>
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
  const [preset, setPreset] = useState<WebhookPresetId>('custom')
  const [payloadMode, setPayloadMode] = useState<'hcms' | 'empty' | 'surrogate_keys'>('hcms')
  const [headersText, setHeadersText] = useState('')
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({})
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

  function parseHeadersText(raw: string): Record<string, string> | undefined {
    const trimmed = raw.trim()
    if (!trimmed) return undefined
    try {
      const parsed = JSON.parse(trimmed) as unknown
      if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return undefined
      const out: Record<string, string> = {}
      for (const [k, v] of Object.entries(parsed as Record<string, unknown>)) {
        if (typeof v === 'string') out[k] = v
      }
      return out
    } catch {
      return undefined
    }
  }

  function webhookBody() {
    return {
      name,
      url,
      ...(secret.trim() ? { secret: secret.trim() } : {}),
      events,
      resourceId,
      status,
      preset: preset === 'custom' ? null : preset,
      payloadMode,
      headers: parseHeadersText(headersText) ?? {},
    }
  }

  const create = useMutation({
    mutationFn: () =>
      api<Webhook>('/admin/api/webhooks', {
        method: 'POST',
        body: JSON.stringify(webhookBody()),
      }),
    onSuccess: (data) => {
      showSuccess(t('common.saved'))
      setFieldErrors({})
      setOpen(false)
      resetForm()
      setSelectedId(data.id)
      void queryClient.invalidateQueries({ queryKey: ['webhooks'] })
    },
    onError: (err) => setFieldErrors(apiFieldErrors(err)),
  })

  const update = useMutation({
    mutationFn: (id: number) =>
      api<Webhook>(`/admin/api/webhooks/${id}`, {
        method: 'PATCH',
        body: JSON.stringify(webhookBody()),
      }),
    onSuccess: () => {
      showSuccess(t('common.saved'))
      setFieldErrors({})
      setOpen(false)
      resetForm()
      void queryClient.invalidateQueries({ queryKey: ['webhooks'] })
    },
    onError: (err) => setFieldErrors(apiFieldErrors(err)),
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
    setPreset('custom')
    setPayloadMode('hcms')
    setHeadersText('')
    setFieldErrors({})
  }

  function applyPreset(next: WebhookPresetId) {
    setPreset(next)
    const cfg = WEBHOOK_PRESETS.find((p) => p.id === next)
    if (!cfg) return
    setPayloadMode(cfg.payloadMode)
    setEvents([...cfg.events])
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
    const presetId: WebhookPresetId =
      WEBHOOK_PRESETS.find((p) => p.id === hook.preset)?.id ?? 'custom'
    setPreset(presetId)
    setPayloadMode(hook.payloadMode ?? 'hcms')
    setHeadersText(
      hook.headers && Object.keys(hook.headers).length > 0
        ? JSON.stringify(hook.headers, null, 2)
        : '',
    )
    setFieldErrors({})
    setOpen(true)
  }

  function toggleEvent(event: WebhookEvent) {
    setEvents((prev) => (prev.includes(event) ? prev.filter((e) => e !== event) : [...prev, event]))
    setFieldErrors((prev) => clearFieldError(prev, 'events'))
  }

  const busy = create.isPending || update.isPending

  return (
    <div className={clsx(styles.root)}>
      <div className={clsx(styles.pageHeader)}>
        <div>
          <h1 className={clsx(styles.title)}>{t('webhooks.title')}</h1>
          <p className={clsx(styles.subtitle)}>{t('webhooks.subtitle')}</p>
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
                  <TableHead className={clsx(styles.alignRight)}>{t('common.actions')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {(webhooks.data ?? []).map((hook) => (
                  <TableRow
                    key={hook.id}
                    className={clsx(selectedId === hook.id && styles.rowSelected)}
                  >
                    <TableCell>
                      <button
                        type="button"
                        className={clsx(styles.nameBtn)}
                        onClick={() => {
                          setSelectedId(hook.id)
                          setTestResult(null)
                        }}
                      >
                        {hook.name}
                      </button>
                      <div className={clsx(styles.mutedXs)}>
                        {hook.resourceId == null
                          ? t('webhooks.allResources')
                          : (resourceLabel.get(hook.resourceId) ?? `#${hook.resourceId}`)}
                      </div>
                    </TableCell>
                    <TableCell className={clsx(styles.urlCell)}>{hook.url}</TableCell>
                    <TableCell className={clsx(styles.mutedXs)}>{hook.events.join(', ')}</TableCell>
                    <TableCell>
                      {hook.status === 'active' ? (
                        <Badge>{t('webhooks.active')}</Badge>
                      ) : (
                        <Badge variant="destructive">{t('webhooks.disabled')}</Badge>
                      )}
                    </TableCell>
                    <TableCell className={clsx(styles.alignRight)}>
                      <div className={clsx(styles.rowActions)}>
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
          {testResult ? <p className={clsx(styles.testResult)}>{testResult}</p> : null}
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
                      <TableCell className={clsx(styles.monoXs)}>{d.event}</TableCell>
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
                      <TableCell className={clsx(styles.mutedXs)}>{d.createdAt}</TableCell>
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
        <DialogContent className={clsx(styles.dialogMd)}>
          <DialogHeader>
            <DialogTitle>
              {isEdit ? t('webhooks.editTitle') : t('webhooks.createTitle')}
            </DialogTitle>
            <DialogDescription>
              {isEdit ? t('webhooks.editHint') : t('webhooks.createHint')}
            </DialogDescription>
          </DialogHeader>

          <Form
            className={clsx(styles.stackMd)}
            onSubmit={() => {
              if (isEdit) {
                if (editingId !== null) update.mutate(editingId)
              } else {
                create.mutate()
              }
            }}
          >
            <div className={clsx(styles.stackXs)}>
              <Label htmlFor="webhook-name">{t('common.name')}</Label>
              <Input
                id="webhook-name"
                value={name}
                aria-invalid={hasFieldError(fieldErrors, 'name') || undefined}
                onChange={(e) => {
                  setName(e.target.value)
                  setFieldErrors((prev) => clearFieldError(prev, 'name'))
                }}
                placeholder={t('webhooks.placeholderName')}
              />
              <FieldError messages={fieldErrors.name} />
            </div>
            <div className={clsx(styles.stackXs)}>
              <Label htmlFor="webhook-preset">{t('webhooks.preset')}</Label>
              <Select
                id="webhook-preset"
                value={preset}
                onChange={(e) => applyPreset(e.target.value as WebhookPresetId)}
              >
                {WEBHOOK_PRESETS.map((p) => (
                  <option key={p.id} value={p.id}>
                    {t(`webhooks.preset.${p.id}`)}
                  </option>
                ))}
              </Select>
              <p className={clsx(styles.mutedXs)}>{t('webhooks.presetHint')}</p>
            </div>
            <div className={clsx(styles.stackXs)}>
              <Label htmlFor="webhook-payload-mode">{t('webhooks.payloadMode')}</Label>
              <Select
                id="webhook-payload-mode"
                value={payloadMode}
                onChange={(e) =>
                  setPayloadMode(e.target.value as 'hcms' | 'empty' | 'surrogate_keys')
                }
              >
                <option value="hcms">{t('webhooks.payloadMode.hcms')}</option>
                <option value="empty">{t('webhooks.payloadMode.empty')}</option>
                <option value="surrogate_keys">{t('webhooks.payloadMode.surrogate_keys')}</option>
              </Select>
            </div>
            <div className={clsx(styles.stackXs)}>
              <Label htmlFor="webhook-url">{t('webhooks.url')}</Label>
              <Input
                id="webhook-url"
                value={url}
                aria-invalid={hasFieldError(fieldErrors, 'url') || undefined}
                onChange={(e) => {
                  setUrl(e.target.value)
                  setFieldErrors((prev) => clearFieldError(prev, 'url'))
                }}
                placeholder="https://example.com/hooks/hcms"
              />
              <FieldError messages={fieldErrors.url} />
            </div>
            <div className={clsx(styles.stackXs)}>
              <div className={clsx(styles.fieldHeader)}>
                <Label htmlFor="webhook-secret">{t('webhooks.secret')}</Label>
                {!isEdit ? (
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => {
                      setSecret(randomSecret())
                      setFieldErrors((prev) => clearFieldError(prev, 'secret'))
                    }}
                  >
                    {t('webhooks.regenerateSecret')}
                  </Button>
                ) : null}
              </div>
              <Input
                id="webhook-secret"
                value={secret}
                aria-invalid={hasFieldError(fieldErrors, 'secret') || undefined}
                onChange={(e) => {
                  setSecret(e.target.value)
                  setFieldErrors((prev) => clearFieldError(prev, 'secret'))
                }}
                placeholder={isEdit ? t('webhooks.secretKeep') : undefined}
              />
              <FieldError messages={fieldErrors.secret} />
            </div>
            <div className={clsx(styles.stackXs)}>
              <Label htmlFor="webhook-headers">{t('webhooks.headers')}</Label>
              <Input
                id="webhook-headers"
                value={headersText}
                onChange={(e) => setHeadersText(e.target.value)}
                placeholder='{"Authorization":"Bearer …"}'
              />
              <p className={clsx(styles.mutedXs)}>{t('webhooks.headersHint')}</p>
              <FieldError messages={fieldErrors.headers} />
            </div>
            <div className={clsx(styles.stackXs)}>
              <Label>{t('webhooks.events')}</Label>
              <div className={clsx(styles.eventsBox)}>
                {WEBHOOK_EVENTS.map((event) => (
                  <label key={event} className={clsx(styles.eventLabel)}>
                    <input
                      type="checkbox"
                      checked={events.includes(event)}
                      onChange={() => toggleEvent(event)}
                    />
                    <span className={clsx(styles.monoXs)}>{event}</span>
                  </label>
                ))}
              </div>
              <FieldError messages={fieldErrors.events} />
            </div>
            <div className={clsx(styles.stackXs)}>
              <Label htmlFor="webhook-resource">{t('webhooks.resource')}</Label>
              <Select
                id="webhook-resource"
                value={resourceId ?? ''}
                aria-invalid={hasFieldError(fieldErrors, 'resourceId') || undefined}
                onChange={(e) => {
                  const value = e.target.value
                  setResourceId(value === '' ? null : Number(value))
                  setFieldErrors((prev) => clearFieldError(prev, 'resourceId'))
                }}
              >
                <option value="">{t('webhooks.allResources')}</option>
                {(resources.data ?? []).map((r) => (
                  <option key={r.id} value={r.id}>
                    {r.label} ({r.slug})
                  </option>
                ))}
              </Select>
              <FieldError messages={fieldErrors.resourceId} />
            </div>
            <div className={clsx(styles.stackXs)}>
              <Label htmlFor="webhook-status">{t('common.status')}</Label>
              <Select
                id="webhook-status"
                value={status}
                aria-invalid={hasFieldError(fieldErrors, 'status') || undefined}
                onChange={(e) => {
                  setStatus(e.target.value === 'disabled' ? 'disabled' : 'active')
                  setFieldErrors((prev) => clearFieldError(prev, 'status'))
                }}
              >
                <option value="active">{t('webhooks.active')}</option>
                <option value="disabled">{t('webhooks.disabled')}</option>
              </Select>
              <FieldError messages={fieldErrors.status} />
            </div>
            <div className={clsx(styles.actions)}>
              <Button variant="outline" onClick={() => setOpen(false)}>
                {t('common.cancel')}
              </Button>
              {isEdit ? (
                <Button
                  type="submit"
                  disabled={!name.trim() || !url.trim() || events.length === 0 || busy}
                >
                  {update.isPending ? t('common.saving') : t('common.save')}
                </Button>
              ) : (
                <Button
                  type="submit"
                  disabled={!name.trim() || !url.trim() || events.length === 0 || busy}
                >
                  {create.isPending ? t('common.creating') : t('common.create')}
                </Button>
              )}
            </div>
          </Form>
        </DialogContent>
      </Dialog>
    </div>
  )
}
