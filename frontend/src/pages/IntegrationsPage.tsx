import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { FormBlockSkeleton, TableSkeleton } from '@/components/skeletons'
import { EmptyState } from '@/components/EmptyState'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { api, ApiError, getToken } from '@/lib/api'
import { copyToClipboard } from '@/lib/clipboard'
import { showError } from '@/lib/toast'
import { useI18n, type MessageKey } from '@/i18n'
import { CodeBlock } from '@/features/docs/CodeBlock'
import { buildEmailSendFetchExample } from './buildEmailSendFetchExample'

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
  dailyQuota: number
  allowedRecipientDomains: string[]
  apiKeyConfigured: boolean
  apiKeyMasked: string | null
  mailgunDomain: string
  mailgunRegion: MailgunRegion
  providers: Record<EmailProvider, ProviderKeyStatus>
}

interface EmailIntegrationApi {
  id: number
  integrationKey: string
  slug: string
  label: string
  enabled: boolean
  defaults: { subject: string; html: string; text: string }
  settings: { allowFromOverride: boolean }
  path: string
}

interface EmailApiDraft {
  slug: string
  label: string
  enabled: boolean
  defaults: { subject: string; html: string; text: string }
  settings: { allowFromOverride: boolean }
}

const PROVIDERS: Array<{ id: EmailProvider; title: string; descriptionKey: MessageKey }> = [
  { id: 'resend', title: 'Resend', descriptionKey: 'integrations.resend.description' },
  { id: 'postmark', title: 'Postmark', descriptionKey: 'integrations.postmark.description' },
  { id: 'mailgun', title: 'Mailgun', descriptionKey: 'integrations.mailgun.description' },
]

const SEND_PATH = '/api/integrations/email/send'

const DEFAULT_PLAYGROUND_BODY = `{
  "to": "you@example.com",
  "subject": "Hello from HCMS",
  "html": "<p>Hi {{name}}</p>",
  "vars": { "name": "Ada" }
}`

function isProvider(value: string): value is EmailProvider {
  return value === 'resend' || value === 'postmark' || value === 'mailgun'
}

function apiKeyPlaceholder(provider: EmailProvider): string {
  if (provider === 'postmark') return 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'
  if (provider === 'mailgun') return 'key-xxxxxxxx'
  return 're_xxxxxxxx'
}

function emptyApiDraft(): EmailApiDraft {
  return {
    slug: '',
    label: '',
    enabled: true,
    defaults: { subject: '', html: '', text: '' },
    settings: { allowFromOverride: true },
  }
}

export function IntegrationsPage() {
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
  const [message, setMessage] = useState<string | null>(null)

  const [editingId, setEditingId] = useState<number | 'new' | null>(null)
  const [draft, setDraft] = useState<EmailApiDraft>(() => emptyApiDraft())
  const [apiMessage, setApiMessage] = useState<string | null>(null)

  const [playgroundPath, setPlaygroundPath] = useState(SEND_PATH)
  const [playgroundToken, setPlaygroundToken] = useState(() => getToken() ?? '')
  const [playgroundBody, setPlaygroundBody] = useState(DEFAULT_PLAYGROUND_BODY)
  const [playgroundResult, setPlaygroundResult] = useState<string | null>(null)
  const [hydratedAt, setHydratedAt] = useState(0)

  const query = useQuery({
    queryKey: ['integrations-email'],
    queryFn: () => api<EmailIntegrationConfig>('/admin/api/integrations/email'),
  })

  const apisQuery = useQuery({
    queryKey: ['integrations-email-apis'],
    queryFn: () => api<EmailIntegrationApi[]>('/admin/api/integrations/email/apis'),
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

  const activeMeta = PROVIDERS.find((p) => p.id === provider) ?? PROVIDERS[0]

  const pathOptions = useMemo(() => {
    const custom = (apisQuery.data ?? []).filter((item) => item.enabled).map((item) => item.path)
    return [SEND_PATH, ...custom]
  }, [apisQuery.data])

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
    onError: (err) =>
      setMessage(err instanceof Error ? err.message : t('integrations.email.testFailed')),
  })

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
      setApiMessage(t('integrations.email.apis.saved'))
      void queryClient.invalidateQueries({ queryKey: ['integrations-email-apis'] })
    },
    onError: (err) => setApiMessage(err instanceof Error ? err.message : t('common.saveFailed')),
  })

  const deleteApi = useMutation({
    mutationFn: (id: number) =>
      api<void>(`/admin/api/integrations/email/apis/${id}`, { method: 'DELETE' }),
    onSuccess: () => {
      setApiMessage(t('integrations.email.apis.deleted'))
      void queryClient.invalidateQueries({ queryKey: ['integrations-email-apis'] })
    },
    onError: (err) => setApiMessage(err instanceof Error ? err.message : t('common.requestFailed')),
  })

  const runPlayground = useMutation({
    mutationFn: async () => {
      let body: unknown
      try {
        body = JSON.parse(playgroundBody)
      } catch {
        throw new Error(t('integrations.email.playground.invalidJson'))
      }
      const headers = new Headers({
        Accept: 'application/json',
        'Content-Type': 'application/json',
      })
      const token = playgroundToken.trim()
      if (token) headers.set('Authorization', `Bearer ${token}`)

      const response = await fetch(playgroundPath, {
        method: 'POST',
        headers,
        body: JSON.stringify(body),
      })
      const text = await response.text()
      let parsed: unknown = text
      try {
        parsed = JSON.parse(text)
      } catch {
        // keep raw
      }
      return { status: response.status, body: parsed }
    },
    onSuccess: (data) => {
      setPlaygroundResult(JSON.stringify(data, null, 2))
      if (data.status >= 400) {
        const body = data.body
        const message =
          body &&
          typeof body === 'object' &&
          'error' in body &&
          body.error &&
          typeof body.error === 'object' &&
          'message' in body.error &&
          typeof body.error.message === 'string'
            ? body.error.message
            : `HTTP ${data.status}`
        showError(message)
      }
    },
    onError: (err) => {
      const message = err instanceof Error ? err.message : t('common.requestFailed')
      setPlaygroundResult(message)
      showError(message)
    },
  })

  const copyPath = async (path: string) => {
    try {
      await copyToClipboard(path)
    } catch {
      // ignore
    }
  }

  const startEdit = (item: EmailIntegrationApi) => {
    setEditingId(item.id)
    setDraft({
      slug: item.slug,
      label: item.label,
      enabled: item.enabled,
      defaults: { ...item.defaults },
      settings: { ...item.settings },
    })
    setApiMessage(null)
  }

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
            <FormBlockSkeleton fields={5} />
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
                      <div className="mt-0.5 text-xs text-muted-foreground">
                        {t(item.descriptionKey)}
                      </div>
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
                <div className="space-y-2">
                  <Label htmlFor="email-quota">{t('integrations.email.dailyQuota')}</Label>
                  <Input
                    id="email-quota"
                    type="number"
                    min={0}
                    value={dailyQuota}
                    onChange={(e) => setDailyQuota(Number(e.target.value) || 0)}
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="email-domains">{t('integrations.email.allowedDomains')}</Label>
                  <Input
                    id="email-domains"
                    value={allowedDomains}
                    onChange={(e) => setAllowedDomains(e.target.value)}
                    placeholder="example.com, client.org"
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
                <p className="text-xs text-muted-foreground">
                  {t('integrations.email.apiKeyHint')}
                </p>
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
                  {test.isPending
                    ? t('integrations.email.testing')
                    : t('integrations.email.sendTest')}
                </Button>
              </div>
            </>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{t('integrations.email.endpoints.title')}</CardTitle>
          <CardDescription>{t('integrations.email.endpoints.hint')}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="flex flex-wrap items-center gap-2">
            <code className="rounded-md border bg-muted px-2 py-1 font-mono text-xs">
              {SEND_PATH}
            </code>
            <Badge variant="secondary">POST</Badge>
            <Button size="sm" variant="outline" onClick={() => void copyPath(SEND_PATH)}>
              {t('integrations.email.endpoints.copy')}
            </Button>
            <Button size="sm" variant="ghost" onClick={() => setPlaygroundPath(SEND_PATH)}>
              {t('integrations.email.endpoints.useInPlayground')}
            </Button>
          </div>
          <p className="text-xs text-muted-foreground">
            {t('integrations.email.endpoints.authHint')}
          </p>
          <CodeBlock
            code={buildEmailSendFetchExample(SEND_PATH)}
            language="js"
            label={t('integrations.email.endpoints.example')}
          />
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-row items-start justify-between gap-4 space-y-0">
          <div className="space-y-1.5">
            <CardTitle>{t('integrations.email.apis.title')}</CardTitle>
            <CardDescription>{t('integrations.email.apis.hint')}</CardDescription>
          </div>
          <Button
            size="sm"
            onClick={() => {
              setEditingId('new')
              setDraft(emptyApiDraft())
              setApiMessage(null)
            }}
          >
            <Plus className="size-4" />
            {t('integrations.email.apis.create')}
          </Button>
        </CardHeader>
        <CardContent className="space-y-4">
          {apiMessage ? <p className="text-sm text-muted-foreground">{apiMessage}</p> : null}

          {apisQuery.isLoading ? (
            <TableSkeleton columns={3} rows={4} />
          ) : (apisQuery.data ?? []).length === 0 && editingId === null ? (
            <EmptyState title={t('integrations.email.apis.empty')} />
          ) : (
            <div className="space-y-2">
              {(apisQuery.data ?? []).map((item) => (
                <div key={item.id} className="space-y-3 rounded-md border px-3 py-2">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="min-w-0 space-y-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">{item.label}</span>
                        <Badge variant={item.enabled ? 'default' : 'secondary'}>
                          {item.enabled ? t('common.enabled') : t('common.disabled')}
                        </Badge>
                      </div>
                      <button
                        type="button"
                        className="font-mono text-xs text-muted-foreground hover:text-foreground"
                        onClick={() => void copyPath(item.path)}
                        title={t('integrations.email.endpoints.copy')}
                      >
                        {item.path}
                      </button>
                    </div>
                    <div className="flex flex-wrap gap-2">
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setPlaygroundPath(item.path)}
                      >
                        {t('integrations.email.endpoints.useInPlayground')}
                      </Button>
                      <Button size="sm" variant="outline" onClick={() => startEdit(item)}>
                        {t('common.edit')}
                      </Button>
                      <Button
                        size="sm"
                        variant="destructive"
                        disabled={deleteApi.isPending}
                        onClick={() => {
                          if (
                            confirm(t('integrations.email.apis.deleteConfirm', { slug: item.slug }))
                          ) {
                            deleteApi.mutate(item.id)
                          }
                        }}
                      >
                        <Trash2 className="size-4" />
                      </Button>
                    </div>
                  </div>
                  <CodeBlock
                    code={buildEmailSendFetchExample(item.path, { withVars: true })}
                    language="js"
                    label={t('integrations.email.endpoints.example')}
                  />
                </div>
              ))}
            </div>
          )}

          {editingId !== null ? (
            <div className="space-y-3 rounded-md border p-4">
              <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-2">
                  <Label htmlFor="email-api-slug">{t('common.slug')}</Label>
                  <Input
                    id="email-api-slug"
                    value={draft.slug}
                    onChange={(e) => setDraft((d) => ({ ...d, slug: e.target.value }))}
                    placeholder="welcome"
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="email-api-label">{t('common.label')}</Label>
                  <Input
                    id="email-api-label"
                    value={draft.label}
                    onChange={(e) => setDraft((d) => ({ ...d, label: e.target.value }))}
                    placeholder="Welcome email"
                  />
                </div>
              </div>
              <label className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={draft.enabled}
                  onChange={(e) => setDraft((d) => ({ ...d, enabled: e.target.checked }))}
                />
                {t('common.enabled')}
              </label>
              <label className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={draft.settings.allowFromOverride}
                  onChange={(e) =>
                    setDraft((d) => ({
                      ...d,
                      settings: { ...d.settings, allowFromOverride: e.target.checked },
                    }))
                  }
                />
                {t('integrations.email.apis.allowFromOverride')}
              </label>
              <div className="space-y-2">
                <Label htmlFor="email-api-subject">
                  {t('integrations.email.apis.defaultSubject')}
                </Label>
                <Input
                  id="email-api-subject"
                  value={draft.defaults.subject}
                  onChange={(e) =>
                    setDraft((d) => ({
                      ...d,
                      defaults: { ...d.defaults, subject: e.target.value },
                    }))
                  }
                  placeholder="Welcome, {{name}}"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="email-api-html">{t('integrations.email.apis.defaultHtml')}</Label>
                <textarea
                  id="email-api-html"
                  rows={4}
                  value={draft.defaults.html}
                  onChange={(e) =>
                    setDraft((d) => ({ ...d, defaults: { ...d.defaults, html: e.target.value } }))
                  }
                  className="flex min-h-[96px] w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  placeholder="<p>Hello {{name}}</p>"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="email-api-text">{t('integrations.email.apis.defaultText')}</Label>
                <textarea
                  id="email-api-text"
                  rows={3}
                  value={draft.defaults.text}
                  onChange={(e) =>
                    setDraft((d) => ({ ...d, defaults: { ...d.defaults, text: e.target.value } }))
                  }
                  className="flex min-h-[72px] w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  placeholder="Hello {{name}}"
                />
              </div>
              <p className="text-xs text-muted-foreground">
                {t('integrations.email.apis.varsHint')}
              </p>
              <div className="flex flex-wrap gap-2">
                <Button
                  disabled={saveApi.isPending || !draft.slug.trim() || !draft.label.trim()}
                  onClick={() => saveApi.mutate()}
                >
                  {saveApi.isPending ? t('common.saving') : t('common.save')}
                </Button>
                <Button
                  variant="outline"
                  onClick={() => {
                    setEditingId(null)
                    setDraft(emptyApiDraft())
                  }}
                >
                  {t('common.cancel')}
                </Button>
              </div>
            </div>
          ) : null}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{t('integrations.email.playground.title')}</CardTitle>
          <CardDescription>{t('integrations.email.playground.hint')}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="email-pg-path">{t('integrations.email.playground.path')}</Label>
              <select
                id="email-pg-path"
                value={playgroundPath}
                onChange={(e) => setPlaygroundPath(e.target.value)}
                className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                {pathOptions.map((path) => (
                  <option key={path} value={path}>
                    {path}
                  </option>
                ))}
                {!pathOptions.includes(playgroundPath) ? (
                  <option value={playgroundPath}>{playgroundPath}</option>
                ) : null}
              </select>
            </div>
            <div className="space-y-2">
              <Label htmlFor="email-pg-token">{t('integrations.email.playground.token')}</Label>
              <Input
                id="email-pg-token"
                type="password"
                value={playgroundToken}
                onChange={(e) => setPlaygroundToken(e.target.value)}
                placeholder="hcms_…"
                autoComplete="off"
              />
            </div>
          </div>
          <div className="space-y-2">
            <CodeBlock
              id="email-pg-body"
              label={t('integrations.email.playground.body')}
              code={playgroundBody}
              language="js"
              editable
              rows={10}
              onChange={setPlaygroundBody}
            />
          </div>
          <Button disabled={runPlayground.isPending} onClick={() => runPlayground.mutate()}>
            {runPlayground.isPending
              ? t('integrations.email.playground.running')
              : t('integrations.email.playground.run')}
          </Button>
          {playgroundResult ? <CodeBlock code={playgroundResult} language="js" /> : null}
          {runPlayground.error instanceof ApiError ? (
            <p className="text-sm text-destructive">{runPlayground.error.message}</p>
          ) : null}
        </CardContent>
      </Card>
    </div>
  )
}
