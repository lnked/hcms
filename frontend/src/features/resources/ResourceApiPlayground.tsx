import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useI18n } from '@/i18n'
import { api, getToken } from '@/lib/api'
import { copyToClipboard } from '@/lib/clipboard'
import { showError } from '@/lib/toast'
import type { SchemaField } from '@/types/field'
import type { Resource } from '@/types/resource'
import type { ResourceCustomApi } from '@/types/resourceApi'
import { buildResourceFetchExample } from './buildResourceFetchExample'

interface ResourceApiPlaygroundProps {
  resource: Resource
  fields: SchemaField[]
  pathPreset?: string | null
}

type HttpMethod = 'GET' | 'POST' | 'PATCH' | 'DELETE'

const controlClass =
  'flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring'

const METHODS: HttpMethod[] = ['GET', 'POST', 'PATCH', 'DELETE']

function normalizeEndpoint(value: string): string {
  const trimmed = value.trim()
  if (!trimmed) return ''
  return trimmed.startsWith('/') ? trimmed : `/${trimmed}`
}

export function ResourceApiPlayground({
  resource,
  fields,
  pathPreset,
}: ResourceApiPlaygroundProps) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [method, setMethod] = useState<HttpMethod>('GET')
  const [path, setPath] = useState(resource.endpoint)
  const [query, setQuery] = useState('limit=20')
  const [body, setBody] = useState('{\n  \n}')
  const [status, setStatus] = useState<number | null>(null)
  const [responseText, setResponseText] = useState<string | null>(null)
  const [sending, setSending] = useState(false)
  const [copied, setCopied] = useState(false)
  const [copiedFetch, setCopiedFetch] = useState(false)
  const [message, setMessage] = useState<string | null>(null)

  const customApisQuery = useQuery({
    queryKey: ['resource-apis', resource.id],
    queryFn: () => api<ResourceCustomApi[]>(`/admin/api/resources/${resource.id}/apis`),
  })

  useEffect(() => {
    if (pathPreset) {
      setPath(pathPreset)
      setMethod('GET')
      setMessage(null)
    }
  }, [pathPreset])

  const normalizedPath = normalizeEndpoint(path)
  const isCustomPath = (customApisQuery.data ?? []).some(
    (custom) => path === custom.path || path.startsWith(`${custom.path}/`),
  )
  const pathDirty = !isCustomPath && normalizedPath !== '' && normalizedPath !== resource.endpoint

  const endpoints = useMemo(() => {
    const lines = [
      `GET ${resource.endpoint}`,
      `GET ${resource.endpoint}/:id`,
      `POST ${resource.endpoint}`,
      `PATCH ${resource.endpoint}/:id`,
      `DELETE ${resource.endpoint}/:id`,
    ]
    for (const custom of customApisQuery.data ?? []) {
      if (!custom.enabled) continue
      lines.push(`GET ${custom.path}`)
      lines.push(`GET ${custom.path}/:id`)
    }
    return lines
  }, [customApisQuery.data, resource.endpoint])

  const fullUrl = useMemo(() => {
    const q = query.trim()
    const base = normalizedPath || path
    return q ? `${base}?${q.replace(/^\?/, '')}` : base
  }, [normalizedPath, path, query])

  const saveEndpoint = useMutation({
    mutationFn: () =>
      api<Resource>(`/admin/api/resources/${resource.id}`, {
        method: 'PATCH',
        body: JSON.stringify({ endpoint: normalizedPath }),
      }),
    onSuccess: (data) => {
      setPath(data.endpoint)
      setMessage(t('resources.playground.pathSaved'))
      queryClient.setQueryData(['resource', resource.id], data)
      void queryClient.invalidateQueries({ queryKey: ['resources'] })
    },
    onError: (err) => setMessage(err instanceof Error ? err.message : t('common.saveFailed')),
  })

  async function send() {
    setSending(true)
    setStatus(null)
    setResponseText(null)
    try {
      const headers = new Headers({ Accept: 'application/json' })
      const token = getToken()
      if (token) headers.set('Authorization', `Bearer ${token}`)
      const init: RequestInit = { method, headers }
      if (method === 'POST' || method === 'PATCH') {
        headers.set('Content-Type', 'application/json')
        init.body = body
      }
      const res = await fetch(fullUrl, init)
      setStatus(res.status)
      const text = await res.text()
      try {
        setResponseText(JSON.stringify(JSON.parse(text), null, 2))
      } catch {
        setResponseText(text || '(empty)')
      }
      if (!res.ok) {
        try {
          const payload = JSON.parse(text) as { error?: { message?: string } }
          showError(payload.error?.message ?? `HTTP ${res.status}`)
        } catch {
          showError(`HTTP ${res.status}`)
        }
      }
    } catch (err) {
      const message = err instanceof Error ? err.message : t('common.requestFailed')
      setStatus(0)
      setResponseText(message)
      showError(message)
    } finally {
      setSending(false)
    }
  }

  async function copyUrl() {
    try {
      await copyToClipboard(fullUrl)
      setCopied(true)
      window.setTimeout(() => setCopied(false), 1500)
    } catch {
      setCopied(false)
    }
  }

  async function copyFetch() {
    try {
      await copyToClipboard(buildResourceFetchExample(resource))
      setCopiedFetch(true)
      window.setTimeout(() => setCopiedFetch(false), 1500)
    } catch {
      setCopiedFetch(false)
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('resources.playground.title')}</CardTitle>
        <CardDescription>{t('resources.playground.hint')}</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="space-y-1 font-mono text-sm text-muted-foreground">
          {endpoints.map((line) => (
            <button
              key={line}
              type="button"
              className="block w-full text-left hover:text-foreground"
              onClick={() => {
                const match = line.match(/^(GET|POST|PATCH|DELETE)\s+(\S+)/)
                if (!match) return
                setMethod(match[1] as HttpMethod)
                setPath(match[2].replace(/\/:id$/, '/1'))
                setMessage(null)
              }}
            >
              {line}
            </button>
          ))}
        </div>

        {fields.length > 0 ? (
          <p className="text-xs text-muted-foreground">
            {t('resources.playground.fieldsHint', { count: fields.length })}
          </p>
        ) : null}

        <div className="grid gap-3 md:grid-cols-[8rem_1fr]">
          <div className="space-y-2">
            <Label htmlFor="api-method">{t('resources.playground.method')}</Label>
            <select
              id="api-method"
              className={controlClass + ' h-9'}
              value={method}
              onChange={(e) => setMethod(e.target.value as HttpMethod)}
            >
              {METHODS.map((m) => (
                <option key={m} value={m}>
                  {m}
                </option>
              ))}
            </select>
          </div>
          <div className="space-y-2">
            <Label htmlFor="api-path">{t('resources.playground.path')}</Label>
            <Input
              id="api-path"
              className="font-mono"
              value={path}
              onChange={(e) => {
                setPath(e.target.value)
                setMessage(null)
              }}
            />
          </div>
        </div>

        <div className="space-y-2">
          <Label htmlFor="api-query">{t('resources.playground.query')}</Label>
          <textarea
            id="api-query"
            className={controlClass + ' h-20 font-mono'}
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="limit=20&sort=id"
          />
        </div>

        {method === 'POST' || method === 'PATCH' ? (
          <div className="space-y-2">
            <Label htmlFor="api-body">{t('resources.playground.body')}</Label>
            <textarea
              id="api-body"
              className={controlClass + ' h-36 font-mono'}
              value={body}
              onChange={(e) => setBody(e.target.value)}
            />
          </div>
        ) : null}

        <div className="flex flex-wrap items-center gap-2">
          <Button
            disabled={!pathDirty || saveEndpoint.isPending}
            onClick={() => saveEndpoint.mutate()}
          >
            {saveEndpoint.isPending ? t('common.saving') : t('resources.playground.savePath')}
          </Button>
          <Button disabled={sending} onClick={() => void send()}>
            {sending ? t('resources.playground.sending') : t('resources.playground.send')}
          </Button>
          <Button variant="outline" onClick={() => void copyUrl()}>
            {copied ? t('resources.playground.copied') : t('resources.playground.copyUrl')}
          </Button>
          <Button variant="outline" onClick={() => void copyFetch()}>
            {copiedFetch ? t('resources.fetchExampleCopied') : t('resources.fetchExampleCopy')}
          </Button>
          <Button variant="outline" onClick={() => window.open('/api/docs', '_blank')}>
            {t('resources.openDocs')}
          </Button>
          {message ? <p className="text-sm text-muted-foreground">{message}</p> : null}
        </div>

        {status !== null ? (
          <div className="space-y-2">
            <p className="text-sm">
              <span className="text-muted-foreground">{t('resources.playground.status')}: </span>
              <span className="font-mono font-medium">{status}</span>
            </p>
            <pre className="max-h-80 overflow-auto rounded-md border bg-muted/40 p-3 font-mono text-xs whitespace-pre-wrap">
              {responseText}
            </pre>
          </div>
        ) : null}
      </CardContent>
    </Card>
  )
}
