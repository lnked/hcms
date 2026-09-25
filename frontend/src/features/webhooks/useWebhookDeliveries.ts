import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { queryKeys } from '@/lib/queryKeys'
import type { WebhookDelivery } from './presets'

export function useWebhookDeliveries(selectedId: number | null) {
  return useQuery({
    queryKey:
      selectedId === null
        ? (['webhooks', 'deliveries', null] as const)
        : queryKeys.webhooks.deliveries(selectedId),
    queryFn: () => api<WebhookDelivery[]>(`/admin/api/webhooks/${selectedId}/deliveries`),
    enabled: selectedId !== null,
  })
}
