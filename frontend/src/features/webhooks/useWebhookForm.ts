import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import { apiFieldErrors, clearFieldError, type FieldErrors } from '@/lib/formErrors'
import { queryKeys } from '@/lib/queryKeys'
import { showSuccess } from '@/lib/toast'
import {
  randomSecret,
  WEBHOOK_EVENTS,
  WEBHOOK_PRESETS,
  type Webhook,
  type WebhookEvent,
  type WebhookPresetId,
} from './presets'

export function useWebhookForm(onCreated: (id: number) => void) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
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

  const isEdit = editingId !== null

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
      onCreated(data.id)
      void queryClient.invalidateQueries({ queryKey: queryKeys.webhooks.list, exact: true })
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
      void queryClient.invalidateQueries({ queryKey: queryKeys.webhooks.list, exact: true })
    },
    onError: (err) => setFieldErrors(apiFieldErrors(err)),
  })

  const busy = create.isPending || update.isPending

  function submit() {
    if (isEdit) {
      if (editingId !== null) update.mutate(editingId)
    } else {
      create.mutate()
    }
  }

  function setDialogOpen(next: boolean) {
    setOpen(next)
    if (!next) resetForm()
  }

  return {
    open,
    setDialogOpen,
    isEdit,
    name,
    setName,
    url,
    setUrl,
    secret,
    setSecret,
    events,
    resourceId,
    setResourceId,
    status,
    setStatus,
    preset,
    payloadMode,
    setPayloadMode,
    headersText,
    setHeadersText,
    fieldErrors,
    setFieldErrors,
    create,
    update,
    busy,
    openCreate,
    openEdit,
    applyPreset,
    toggleEvent,
    submit,
    regenerateSecret: () => {
      setSecret(randomSecret())
      setFieldErrors((prev) => clearFieldError(prev, 'secret'))
    },
  }
}
