import { useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Copy } from 'lucide-react'
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
import { DatePickerField } from '@/components/ui/date-picker'
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
import { copyToClipboard } from '@/lib/clipboard'
import type { Resource } from '@/types/resource'
import {
  parseLines,
  TokenRestrictionsFields,
  type TokenRestrictions,
} from './TokenRestrictionsFields'

interface TokenGrant {
  resourceId: number | null
  canRead: boolean
  canCreate: boolean
  canUpdate: boolean
  canDelete: boolean
}

interface IntegrationGrant {
  integrationKey: string
  canUse: boolean
}

interface ApiToken extends TokenRestrictions {
  id: number
  name: string
  prefix: string
  expiresAt: string | null
  revokedAt: string | null
  lastUsedAt: string | null
  createdAt: string
  grants: TokenGrant[]
  integrationGrants: IntegrationGrant[]
}

const emptyGrant = (): TokenGrant => ({
  resourceId: null,
  canRead: true,
  canCreate: false,
  canUpdate: false,
  canDelete: false,
})

function isRestricted(token: ApiToken): boolean {
  return (
    (token.allowedOrigins ?? []).length > 0 ||
    (token.allowedIps ?? []).length > 0 ||
    (token.requireOrigin ?? false)
  )
}

function stripGrantIds(grants: TokenGrant[]): TokenGrant[] {
  return grants.map(({ resourceId, canRead, canCreate, canUpdate, canDelete }) => ({
    resourceId,
    canRead,
    canCreate,
    canUpdate,
    canDelete,
  }))
}

export function TokensPage() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [name, setName] = useState('')
  const [expiresAt, setExpiresAt] = useState('')
  const [grants, setGrants] = useState<TokenGrant[]>([emptyGrant()])
  const [emailCanUse, setEmailCanUse] = useState(false)
  const [originsText, setOriginsText] = useState('')
  const [ipsText, setIpsText] = useState('')
  const [requireOrigin, setRequireOrigin] = useState(false)
  const [createdToken, setCreatedToken] = useState<string | null>(null)
  const [tokenCopied, setTokenCopied] = useState(false)
  const tokenCopyTimer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const [error, setError] = useState<string | null>(null)

  const isEdit = editingId !== null

  async function copyCreatedToken() {
    if (!createdToken) return
    try {
      await copyToClipboard(createdToken)
      setTokenCopied(true)
      if (tokenCopyTimer.current) clearTimeout(tokenCopyTimer.current)
      tokenCopyTimer.current = setTimeout(() => setTokenCopied(false), 1500)
    } catch {
      setTokenCopied(false)
    }
  }

  const tokens = useQuery({
    queryKey: ['api-tokens'],
    queryFn: () => api<ApiToken[]>('/admin/api/tokens'),
  })

  const resources = useQuery({
    queryKey: ['resources'],
    queryFn: () => api<Resource[]>('/admin/api/resources'),
  })

  const resourceLabel = useMemo(() => {
    const map = new Map<number, string>()
    for (const r of resources.data ?? []) map.set(r.id, r.label)
    return map
  }, [resources.data])

  function tokenPayload() {
    return JSON.stringify({
      name,
      expiresAt: expiresAt || null,
      grants,
      integrationGrants: [{ integrationKey: 'email', canUse: emailCanUse }],
      allowedOrigins: parseLines(originsText),
      requireOrigin,
      allowedIps: parseLines(ipsText),
    })
  }

  const create = useMutation({
    mutationFn: () =>
      api<{ token: string; meta: ApiToken }>('/admin/api/tokens', {
        method: 'POST',
        body: tokenPayload(),
      }),
    onSuccess: (data) => {
      setCreatedToken(data.token)
      setError(null)
      void queryClient.invalidateQueries({ queryKey: ['api-tokens'] })
    },
    onError: (err) => setError(err instanceof Error ? err.message : t('common.createFailed')),
  })

  const update = useMutation({
    mutationFn: (id: number) =>
      api<ApiToken>(`/admin/api/tokens/${id}`, {
        method: 'PATCH',
        body: tokenPayload(),
      }),
    onSuccess: () => {
      setError(null)
      setOpen(false)
      resetForm()
      void queryClient.invalidateQueries({ queryKey: ['api-tokens'] })
    },
    onError: (err) => setError(err instanceof Error ? err.message : t('common.saveFailed')),
  })

  const revoke = useMutation({
    mutationFn: (id: number) => api<void>(`/admin/api/tokens/${id}`, { method: 'DELETE' }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['api-tokens'] }),
  })

  const restore = useMutation({
    mutationFn: (id: number) =>
      api<ApiToken>(`/admin/api/tokens/${id}/restore`, { method: 'POST', body: '{}' }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['api-tokens'] }),
  })

  function resetForm() {
    setEditingId(null)
    setName('')
    setExpiresAt('')
    setGrants([emptyGrant()])
    setEmailCanUse(false)
    setOriginsText('')
    setIpsText('')
    setRequireOrigin(false)
    setCreatedToken(null)
    setTokenCopied(false)
    if (tokenCopyTimer.current) clearTimeout(tokenCopyTimer.current)
    setError(null)
  }

  function openCreate() {
    resetForm()
    setOpen(true)
  }

  function openEdit(token: ApiToken) {
    setEditingId(token.id)
    setName(token.name)
    setExpiresAt(token.expiresAt ?? '')
    setGrants(token.grants.length > 0 ? stripGrantIds(token.grants) : [emptyGrant()])
    setEmailCanUse(
      (token.integrationGrants ?? []).some((g) => g.integrationKey === 'email' && g.canUse),
    )
    setOriginsText((token.allowedOrigins ?? []).join('\n'))
    setIpsText((token.allowedIps ?? []).join('\n'))
    setRequireOrigin(token.requireOrigin ?? false)
    setCreatedToken(null)
    setTokenCopied(false)
    setError(null)
    setOpen(true)
  }

  const busy = create.isPending || update.isPending

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">{t('tokens.title')}</h1>
          <p className="text-sm text-muted-foreground">{t('tokens.subtitle')}</p>
        </div>
        <Button onClick={openCreate}>{t('tokens.create')}</Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>{t('tokens.listTitle')}</CardTitle>
          <CardDescription>{t('tokens.listHint')}</CardDescription>
        </CardHeader>
        <CardContent>
          {tokens.isLoading ? (
            <TableSkeleton columns={6} rows={5} />
          ) : (tokens.data ?? []).length === 0 ? (
            <EmptyState title={t('tokens.empty')} />
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('common.name')}</TableHead>
                  <TableHead>{t('tokens.prefix')}</TableHead>
                  <TableHead>{t('tokens.grants')}</TableHead>
                  <TableHead>{t('tokens.restrictionsColumn')}</TableHead>
                  <TableHead>{t('common.status')}</TableHead>
                  <TableHead className="text-right">{t('common.actions')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {(tokens.data ?? []).map((token) => (
                  <TableRow key={token.id}>
                    <TableCell>{token.name}</TableCell>
                    <TableCell className="font-mono text-xs">{token.prefix}…</TableCell>
                    <TableCell className="text-xs text-muted-foreground">
                      {[
                        ...(token.grants.length === 0
                          ? []
                          : token.grants.map((g) =>
                              g.resourceId == null
                                ? t('tokens.global')
                                : (resourceLabel.get(g.resourceId) ?? `#${g.resourceId}`),
                            )),
                        ...(token.integrationGrants ?? [])
                          .filter((g) => g.canUse)
                          .map((g) =>
                            g.integrationKey === 'email'
                              ? t('tokens.integrationEmail')
                              : g.integrationKey,
                          ),
                      ].join(', ') || t('common.none')}
                    </TableCell>
                    <TableCell>
                      <div className="flex flex-wrap gap-1">
                        {isRestricted(token) ? (
                          <>
                            {(token.allowedOrigins ?? []).length > 0 ? (
                              <Badge variant="secondary">
                                {t('tokens.originsLocked', {
                                  count: token.allowedOrigins.length,
                                })}
                              </Badge>
                            ) : null}
                            {token.requireOrigin ? (
                              <Badge variant="secondary">{t('tokens.browserOnly')}</Badge>
                            ) : null}
                            {(token.allowedIps ?? []).length > 0 ? (
                              <Badge variant="secondary">
                                {t('tokens.ipsLocked', { count: token.allowedIps.length })}
                              </Badge>
                            ) : null}
                          </>
                        ) : (
                          <Badge
                            variant="outline"
                            className="border-amber-500/60 text-amber-700 dark:text-amber-400"
                            title={t('tokens.unlockedWarning')}
                          >
                            {t('tokens.originsAny')}
                          </Badge>
                        )}
                      </div>
                    </TableCell>
                    <TableCell>
                      {token.revokedAt ? (
                        <Badge variant="destructive">{t('tokens.revoked')}</Badge>
                      ) : (
                        <Badge>{t('tokens.active')}</Badge>
                      )}
                    </TableCell>
                    <TableCell className="text-right">
                      <div className="flex justify-end gap-2">
                        <Button size="sm" variant="outline" onClick={() => openEdit(token)}>
                          {t('common.edit')}
                        </Button>
                        {token.revokedAt ? (
                          <Button
                            size="sm"
                            disabled={restore.isPending}
                            onClick={() => {
                              if (confirm(t('tokens.restoreConfirm', { name: token.name }))) {
                                restore.mutate(token.id)
                              }
                            }}
                          >
                            {t('tokens.restore')}
                          </Button>
                        ) : (
                          <Button
                            size="sm"
                            variant="destructive"
                            disabled={revoke.isPending}
                            onClick={() => {
                              if (confirm(t('tokens.revokeConfirm', { name: token.name }))) {
                                revoke.mutate(token.id)
                              }
                            }}
                          >
                            {t('tokens.revoke')}
                          </Button>
                        )}
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <Dialog
        open={open}
        onOpenChange={(next) => {
          setOpen(next)
          if (!next) resetForm()
        }}
      >
        <DialogContent className="max-w-xl">
          <DialogHeader>
            <DialogTitle>{isEdit ? t('tokens.editTitle') : t('tokens.createTitle')}</DialogTitle>
            <DialogDescription>
              {isEdit ? t('tokens.editHint') : t('tokens.createHint')}
            </DialogDescription>
          </DialogHeader>

          {createdToken ? (
            <div className="space-y-3">
              <p className="text-sm">{t('tokens.copyOnce')}</p>
              <div className="relative">
                <Button
                  type="button"
                  size="icon"
                  variant="outline"
                  className="absolute top-2 right-2 z-10 h-7 w-7 bg-background/90"
                  onClick={() => void copyCreatedToken()}
                  title={tokenCopied ? t('docs.copied') : t('docs.copy')}
                  aria-label={tokenCopied ? t('docs.copied') : t('docs.copy')}
                >
                  {tokenCopied ? (
                    <Check className="h-3.5 w-3.5" />
                  ) : (
                    <Copy className="h-3.5 w-3.5" />
                  )}
                </Button>
                <code className="block break-all rounded-md border bg-muted p-3 pr-11 text-xs">
                  {createdToken}
                </code>
              </div>
              <Button onClick={() => setOpen(false)}>{t('common.done')}</Button>
            </div>
          ) : (
            <div className="space-y-4">
              <div className="space-y-1.5">
                <Label htmlFor="token-name">{t('common.name')}</Label>
                <Input
                  id="token-name"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  placeholder={t('tokens.placeholderName')}
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="token-expires">{t('tokens.expires')}</Label>
                <DatePickerField
                  id="token-expires"
                  value={expiresAt}
                  granularity="minute"
                  format="DD.MM.YYYY HH:mm"
                  onChange={(next) => setExpiresAt(next ?? '')}
                />
              </div>

              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <Label>{t('tokens.grants')}</Label>
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => setGrants((g) => [...g, emptyGrant()])}
                  >
                    {t('tokens.addGrant')}
                  </Button>
                </div>
                {grants.map((grant, index) => (
                  <div key={index} className="space-y-2 rounded-md border p-3">
                    <Select
                      value={grant.resourceId ?? ''}
                      onChange={(e) => {
                        const value = e.target.value
                        setGrants((rows) =>
                          rows.map((row, i) =>
                            i === index
                              ? { ...row, resourceId: value === '' ? null : Number(value) }
                              : row,
                          ),
                        )
                      }}
                    >
                      <option value="">{t('tokens.allResources')}</option>
                      {(resources.data ?? []).map((r) => (
                        <option key={r.id} value={r.id}>
                          {r.label} ({r.slug})
                        </option>
                      ))}
                    </Select>
                    <div className="flex flex-wrap gap-3 text-sm">
                      {(
                        [
                          ['canRead', 'tokens.canRead'],
                          ['canCreate', 'tokens.canCreate'],
                          ['canUpdate', 'tokens.canUpdate'],
                          ['canDelete', 'tokens.canDelete'],
                        ] as const
                      ).map(([key, labelKey]) => (
                        <label key={key} className="flex items-center gap-1.5">
                          <input
                            type="checkbox"
                            checked={grant[key]}
                            onChange={(e) => {
                              setGrants((rows) =>
                                rows.map((row, i) =>
                                  i === index ? { ...row, [key]: e.target.checked } : row,
                                ),
                              )
                            }}
                          />
                          {t(labelKey)}
                        </label>
                      ))}
                    </div>
                  </div>
                ))}
                <label className="flex items-center gap-2 rounded-md border p-3 text-sm">
                  <input
                    type="checkbox"
                    checked={emailCanUse}
                    onChange={(e) => setEmailCanUse(e.target.checked)}
                  />
                  <span>{t('tokens.integrationEmailGrant')}</span>
                </label>
              </div>

              <TokenRestrictionsFields
                originsText={originsText}
                ipsText={ipsText}
                requireOrigin={requireOrigin}
                onOriginsTextChange={setOriginsText}
                onIpsTextChange={setIpsText}
                onRequireOriginChange={setRequireOrigin}
              />

              {error ? <p className="text-sm text-destructive">{error}</p> : null}
              <div className="flex justify-end gap-2">
                <Button variant="outline" onClick={() => setOpen(false)}>
                  {t('common.cancel')}
                </Button>
                {isEdit ? (
                  <Button
                    disabled={!name.trim() || busy}
                    onClick={() => {
                      if (editingId !== null) update.mutate(editingId)
                    }}
                  >
                    {update.isPending ? t('common.saving') : t('common.save')}
                  </Button>
                ) : (
                  <Button disabled={!name.trim() || busy} onClick={() => create.mutate()}>
                    {create.isPending ? t('common.creating') : t('common.create')}
                  </Button>
                )}
              </div>
            </div>
          )}
        </DialogContent>
      </Dialog>
    </div>
  )
}
