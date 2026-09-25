import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useMemo, useState } from 'react'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import { queryKeys } from '@/lib/queryKeys'
import { showSuccess } from '@/lib/toast'
import {
  emptyApiDraft,
  SEND_PATH,
  type EmailApiDraft,
  type EmailIntegrationApi,
} from './emailTypes'

export function useEmailApis() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [editingId, setEditingId] = useState<number | 'new' | null>(null)
  const [draft, setDraft] = useState<EmailApiDraft>(() => emptyApiDraft())

  const apisQuery = useQuery({
    queryKey: queryKeys.integrations.emailApis,
    queryFn: () => api<EmailIntegrationApi[]>('/admin/api/integrations/email/apis'),
  })

  const pathOptions = useMemo(() => {
    const custom = (apisQuery.data ?? []).filter((item) => item.enabled).map((item) => item.path)
    return [SEND_PATH, ...custom]
  }, [apisQuery.data])

  const saveApi = useMutation({
    mutationFn: async () => {
      if (editingId === 'new') {
        return api<EmailIntegrationApi>('/admin/api/integrations/email/apis', {
          method: 'POST',
          body: JSON.stringify(draft),
        })
      }
      return api<EmailIntegrationApi>(`/admin/api/integrations/email/apis/${editingId}`, {
        method: 'PATCH',
        body: JSON.stringify(draft),
      })
    },
    onSuccess: () => {
      setEditingId(null)
      setDraft(emptyApiDraft())
      showSuccess(t('integrations.email.apis.saved'))
      void queryClient.invalidateQueries({ queryKey: queryKeys.integrations.emailApis })
    },
  })

  const deleteApi = useMutation({
    mutationFn: (id: number) =>
      api<void>(`/admin/api/integrations/email/apis/${id}`, { method: 'DELETE' }),
    onSuccess: () => {
      showSuccess(t('integrations.email.apis.deleted'))
      void queryClient.invalidateQueries({ queryKey: queryKeys.integrations.emailApis })
    },
  })

  const startEdit = (item: EmailIntegrationApi) => {
    setEditingId(item.id)
    setDraft({
      slug: item.slug,
      label: item.label,
      enabled: item.enabled,
      defaults: { ...item.defaults },
      settings: { ...item.settings },
    })
  }

  const startCreate = () => {
    setEditingId('new')
    setDraft(emptyApiDraft())
  }

  const cancelEdit = () => {
    setEditingId(null)
    setDraft(emptyApiDraft())
  }

  return {
    apisQuery,
    pathOptions,
    editingId,
    draft,
    setDraft,
    saveApi,
    deleteApi,
    startEdit,
    startCreate,
    cancelEdit,
  }
}
