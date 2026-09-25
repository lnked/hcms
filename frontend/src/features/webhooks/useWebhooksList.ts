import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useMemo, useState } from 'react'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import { queryKeys } from '@/lib/queryKeys'
import type { Resource } from '@/types/resource'
import type { Webhook, WebhookDelivery } from './presets'

export function useWebhooksList() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [testResult, setTestResult] = useState<string | null>(null)

  const webhooks = useQuery({
    queryKey: queryKeys.webhooks.list,
    queryFn: () => api<Webhook[]>('/admin/api/webhooks'),
  })

  const resources = useQuery({
    queryKey: queryKeys.resources.all,
    queryFn: () => api<Resource[]>('/admin/api/resources'),
  })

  const resourceLabel = useMemo(() => {
    const map = new Map<number, string>()
    for (const r of resources.data ?? []) map.set(r.id, r.label)
    return map
  }, [resources.data])

  const remove = useMutation({
    mutationFn: (id: number) => api<void>(`/admin/api/webhooks/${id}`, { method: 'DELETE' }),
    onSuccess: (_data, id) => {
      if (selectedId === id) setSelectedId(null)
      void queryClient.invalidateQueries({ queryKey: queryKeys.webhooks.list, exact: true })
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
    onSuccess: () =>
      void queryClient.invalidateQueries({ queryKey: queryKeys.webhooks.list, exact: true }),
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
      void queryClient.invalidateQueries({ queryKey: queryKeys.webhooks.deliveries(id) })
    },
    onError: (err) =>
      setTestResult(err instanceof Error ? err.message : t('webhooks.testFailGeneric')),
  })

  const selectWebhook = (id: number) => {
    setSelectedId(id)
    setTestResult(null)
  }

  return {
    webhooks,
    resources,
    resourceLabel,
    selectedId,
    setSelectedId,
    selectWebhook,
    testResult,
    setTestResult,
    remove,
    toggleStatus,
    test,
  }
}
