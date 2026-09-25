import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import { queryKeys } from '@/lib/queryKeys'
import { showSuccess } from '@/lib/toast'
import {
  isProvider,
  PROVIDERS,
  type EmailIntegrationConfig,
  type EmailProvider,
  type MailgunRegion,
} from './emailTypes'

export function useEmailIntegration() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [provider, setProvider] = useState<EmailProvider>('resend')
  const [enabled, setEnabled] = useState(false)
  const [fromEmail, setFromEmail] = useState('')
  const [fromName, setFromName] = useState('')
  const [dailyQuota, setDailyQuota] = useState(100)
  const [allowedDomains, setAllowedDomains] = useState('')
  const [apiKey, setApiKey] = useState('')
  const [mailgunDomain, setMailgunDomain] = useState('')
  const [mailgunRegion, setMailgunRegion] = useState<MailgunRegion>('us')
  const [testTo, setTestTo] = useState('')
  const [hydratedAt, setHydratedAt] = useState(0)

  const query = useQuery({
    queryKey: queryKeys.integrations.email,
    queryFn: () => api<EmailIntegrationConfig>('/admin/api/integrations/email'),
  })

  if (query.data && query.dataUpdatedAt !== hydratedAt) {
    setHydratedAt(query.dataUpdatedAt)
    setProvider(isProvider(query.data.provider) ? query.data.provider : 'resend')
    setEnabled(query.data.enabled)
    setFromEmail(query.data.fromEmail)
    setFromName(query.data.fromName)
    setDailyQuota(query.data.dailyQuota ?? 100)
    setAllowedDomains((query.data.allowedRecipientDomains ?? []).join(', '))
    setMailgunDomain(query.data.mailgunDomain)
    setMailgunRegion(query.data.mailgunRegion === 'eu' ? 'eu' : 'us')
    setApiKey('')
  }

  const activeMeta = PROVIDERS.find((p) => p.id === provider) ?? PROVIDERS[0]!

  const save = useMutation({
    mutationFn: () =>
      api<EmailIntegrationConfig>('/admin/api/integrations/email', {
        method: 'PUT',
        body: JSON.stringify({
          provider,
          enabled,
          fromEmail,
          fromName,
          dailyQuota,
          allowedRecipientDomains: allowedDomains
            .split(',')
            .map((s) => s.trim())
            .filter(Boolean),
          mailgunDomain,
          mailgunRegion,
          ...(apiKey.trim() !== '' ? { apiKey: apiKey.trim() } : {}),
        }),
      }),
    onSuccess: (data) => {
      setApiKey('')
      setProvider(isProvider(data.provider) ? data.provider : 'resend')
      setEnabled(data.enabled)
      setFromEmail(data.fromEmail)
      setFromName(data.fromName)
      setDailyQuota(data.dailyQuota ?? 100)
      setAllowedDomains((data.allowedRecipientDomains ?? []).join(', '))
      setMailgunDomain(data.mailgunDomain)
      setMailgunRegion(data.mailgunRegion === 'eu' ? 'eu' : 'us')
      void queryClient.invalidateQueries({ queryKey: queryKeys.integrations.email })
      showSuccess(t('integrations.email.saved'))
    },
  })

  const test = useMutation({
    mutationFn: () =>
      api<{ ok: boolean; to: string }>('/admin/api/integrations/email/test', {
        method: 'POST',
        body: JSON.stringify({ to: testTo.trim() }),
      }),
    onSuccess: (data) => showSuccess(t('integrations.email.testSent', { to: data.to })),
  })

  return {
    query,
    provider,
    setProvider,
    enabled,
    setEnabled,
    fromEmail,
    setFromEmail,
    fromName,
    setFromName,
    dailyQuota,
    setDailyQuota,
    allowedDomains,
    setAllowedDomains,
    apiKey,
    setApiKey,
    mailgunDomain,
    setMailgunDomain,
    mailgunRegion,
    setMailgunRegion,
    testTo,
    setTestTo,
    activeMeta,
    save,
    test,
  }
}
