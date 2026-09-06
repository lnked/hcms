import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { api } from '@/lib/api'
import { useI18n, type MessageKey } from '@/i18n'

type EmailProvider = 'resend' | 'postmark' | 'mailgun'
type MailgunRegion = 'us' | 'eu'

interface ProviderKeyStatus {
  apiKeyConfigured: boolean
  apiKeyMasked: string | null
}

interface EmailIntegrationConfig {
  provider: EmailProvider
  enabled: boolean
  fromEmail: string
  fromName: string
  apiKeyConfigured: boolean
  apiKeyMasked: string | null
  mailgunDomain: string
  mailgunRegion: MailgunRegion
  providers: Record<EmailProvider, ProviderKeyStatus>
}

const PROVIDERS: Array<{ id: EmailProvider; title: string; descriptionKey: MessageKey }> = [
  { id: 'resend', title: 'Resend', descriptionKey: 'integrations.resend.description' },
  { id: 'postmark', title: 'Postmark', descriptionKey: 'integrations.postmark.description' },
  { id: 'mailgun', title: 'Mailgun', descriptionKey: 'integrations.mailgun.description' },
]

function isProvider(value: string): value is EmailProvider {
  return value === 'resend' || value === 'postmark' || value === 'mailgun'
}

function apiKeyPlaceholder(provider: EmailProvider): string {
  if (provider === 'postmark') return 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'
  if (provider === 'mailgun') return 'key-xxxxxxxx'
  return 're_xxxxxxxx'
}

export function IntegrationsPage() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [provider, setProvider] = useState<EmailProvider>('resend')
  const [enabled, setEnabled] = useState(false)
  const [fromEmail, setFromEmail] = useState('')
  const [fromName, setFromName] = useState('')
  const [apiKey, setApiKey] = useState('')
  const [mailgunDomain, setMailgunDomain] = useState('')
  const [mailgunRegion, setMailgunRegion] = useState<MailgunRegion>('us')
  const [testTo, setTestTo] = useState('')
  const [message, setMessage] = useState<string | null>(null)

  const query = useQuery({
    queryKey: ['integrations-email'],
    queryFn: () => api<EmailIntegrationConfig>('/admin/api/integrations/email'),
  })

  useEffect(() => {
    if (!query.data) return
    setProvider(isProvider(query.data.provider) ? query.data.provider : 'resend')
    setEnabled(query.data.enabled)
    setFromEmail(query.data.fromEmail)
    setFromName(query.data.fromName)
    setMailgunDomain(query.data.mailgunDomain)
    setMailgunRegion(query.data.mailgunRegion === 'eu' ? 'eu' : 'us')
    setApiKey('')
  }, [query.data])

  const activeMeta = PROVIDERS.find((p) => p.id === provider) ?? PROVIDERS[0]

  const save = useMutation({
    mutationFn: () =>
      api<EmailIntegrationConfig>('/admin/api/integrations/email', {
        method: 'PUT',
        body: JSON.stringify({
          provider,
          enabled,
          fromEmail,
          fromName,
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
      setMailgunDomain(data.mailgunDomain)
      setMailgunRegion(data.mailgunRegion === 'eu' ? 'eu' : 'us')
      void queryClient.invalidateQueries({ queryKey: ['integrations-email'] })
      setMessage(t('integrations.email.saved'))
    },
    onError: (err) => setMessage(err instanceof Error ? err.message : t('common.saveFailed')),
  })

  const test = useMutation({
    mutationFn: () =>
      api<{ ok: boolean; to: string }>('/admin/api/integrations/email/test', {
        method: 'POST',
        body: JSON.stringify({ to: testTo.trim() }),
      }),
    onSuccess: (data) => setMessage(t('integrations.email.testSent', { to: data.to })),
    onError: (err) => setMessage(err instanceof Error ? err.message : t('integrations.email.testFailed')),
  })

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">{t('integrations.title')}</h1>
        <p className="mt-1 text-sm text-muted-foreground">{t('integrations.description')}</p>
      </div>

      {message ? <p className="text-sm text-muted-foreground">{message}</p> : null}

      <Card>
        <CardHeader className="flex flex-row items-start justify-between gap-4 space-y-0">
          <div className="space-y-1.5">
            <CardTitle>{t('integrations.email.cardTitle')}</CardTitle>
            <CardDescription>{t(activeMeta.descriptionKey)}</CardDescription>
          </div>
          <Badge variant={query.data?.enabled ? 'default' : 'secondary'}>
            {query.data?.enabled ? t('common.enabled') : t('common.disabled')}
          </Badge>
        </CardHeader>
        <CardContent className="space-y-4">
          {query.isLoading ? (
            <p className="text-sm text-muted-foreground">{t('common.loading')}</p>
          ) : query.isError ? (
            <p className="text-sm text-destructive">
              {query.error instanceof Error ? query.error.message : t('common.requestFailed')}
            </p>
          ) : (
            <>
              <div className="space-y-2">
                <Label>{t('integrations.email.provider')}</Label>
                <div className="grid gap-2 sm:grid-cols-3">
                  {PROVIDERS.map((item) => (
                    <button
                      key={item.id}
                      type="button"
                      onClick={() => {
                        setProvider(item.id)
                        setApiKey('')
                        setMessage(null)
                      }}
                      className={
                        provider === item.id
                          ? 'rounded-md border border-primary bg-primary/5 px-3 py-2 text-left text-sm'
                          : 'rounded-md border border-input px-3 py-2 text-left text-sm hover:bg-accent'
                      }
                    >
                      <div className="font-medium">{item.title}</div>
                      <div className="mt-0.5 text-xs text-muted-foreground">{t(item.descriptionKey)}</div>
                    </button>
                  ))}
                </div>
              </div>

              <label className="flex items-start gap-2 text-sm">
                <input
                  type="checkbox"
                  className="mt-1"
                  checked={enabled}
                  onChange={(e) => setEnabled(e.target.checked)}
                />
                <span>{t('integrations.email.enabled')}</span>
              </label>

              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <Label htmlFor="email-from">{t('integrations.email.fromEmail')}</Label>
                  <Input
                    id="email-from"
                    type="email"
                    value={fromEmail}
                    onChange={(e) => setFromEmail(e.target.value)}
                    placeholder="noreply@example.com"
                    autoComplete="off"
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="email-from-name">{t('integrations.email.fromName')}</Label>
                  <Input
                    id="email-from-name"
                    value={fromName}
                    onChange={(e) => setFromName(e.target.value)}
                    placeholder="HCMS"
                    autoComplete="off"
                  />
                </div>
              </div>

              <div className="space-y-2">
                <Label htmlFor="email-api-key">{t('integrations.email.apiKey')}</Label>
                <Input
                  id="email-api-key"
                  type="password"
                  value={apiKey}
                  onChange={(e) => setApiKey(e.target.value)}
                  placeholder={
                    query.data?.providers[provider]?.apiKeyConfigured
                      ? t('integrations.email.apiKeyKeep', {
                          masked: query.data.providers[provider].apiKeyMasked ?? '••••',
                        })
                      : apiKeyPlaceholder(provider)
                  }
                  autoComplete="new-password"
                />
                <p className="text-xs text-muted-foreground">{t('integrations.email.apiKeyHint')}</p>
              </div>

              {provider === 'mailgun' ? (
                <div className="grid gap-4 sm:grid-cols-2">
                  <div className="space-y-2">
                    <Label htmlFor="mailgun-domain">{t('integrations.email.mailgunDomain')}</Label>
                    <Input
                      id="mailgun-domain"
                      value={mailgunDomain}
                      onChange={(e) => setMailgunDomain(e.target.value)}
                      placeholder="mg.example.com"
                      autoComplete="off"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="mailgun-region">{t('integrations.email.mailgunRegion')}</Label>
                    <select
                      id="mailgun-region"
                      value={mailgunRegion}
                      onChange={(e) => setMailgunRegion(e.target.value === 'eu' ? 'eu' : 'us')}
                      className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                      <option value="us">{t('integrations.email.mailgunRegionUs')}</option>
                      <option value="eu">{t('integrations.email.mailgunRegionEu')}</option>
                    </select>
                  </div>
                </div>
              ) : null}

              <div className="flex flex-wrap gap-2">
                <Button disabled={save.isPending} onClick={() => save.mutate()}>
                  {save.isPending ? t('common.saving') : t('common.save')}
                </Button>
              </div>

              <div className="space-y-3 border-t border-border pt-4">
                <div className="space-y-2">
                  <Label htmlFor="email-test-to">{t('integrations.email.testTo')}</Label>
                  <Input
                    id="email-test-to"
                    type="email"
                    value={testTo}
                    onChange={(e) => setTestTo(e.target.value)}
                    placeholder="you@example.com"
                    autoComplete="email"
                  />
                </div>
                <Button
                  variant="outline"
                  disabled={test.isPending || testTo.trim() === ''}
                  onClick={() => test.mutate()}
                >
                  {test.isPending ? t('integrations.email.testing') : t('integrations.email.sendTest')}
                </Button>
              </div>
            </>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
