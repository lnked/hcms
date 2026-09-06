import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
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
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
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
import type { Resource } from '@/types/resource'

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

interface ApiToken {
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

export function TokensPage() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [name, setName] = useState('')
  const [expiresAt, setExpiresAt] = useState('')
  const [grants, setGrants] = useState<TokenGrant[]>([emptyGrant()])
  const [emailCanUse, setEmailCanUse] = useState(false)
  const [createdToken, setCreatedToken] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

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

  const create = useMutation({
    mutationFn: () =>
      api<{ token: string; meta: ApiToken }>('/admin/api/tokens', {
        method: 'POST',
        body: JSON.stringify({
          name,
          expiresAt: expiresAt || null,
          grants,
          integrationGrants: [{ integrationKey: 'email', canUse: emailCanUse }],
        }),
      }),
    onSuccess: (data) => {
      setCreatedToken(data.token)
      setError(null)
      void queryClient.invalidateQueries({ queryKey: ['api-tokens'] })
    },
    onError: (err) => setError(err instanceof Error ? err.message : t('common.createFailed')),
  })

  const revoke = useMutation({
    mutationFn: (id: number) => api<void>(`/admin/api/tokens/${id}`, { method: 'DELETE' }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['api-tokens'] }),
  })

  function resetForm() {
    setName('')
    setExpiresAt('')
    setGrants([emptyGrant()])
    setEmailCanUse(false)
    setCreatedToken(null)
    setError(null)
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">{t('tokens.title')}</h1>
          <p className="text-sm text-muted-foreground">{t('tokens.subtitle')}</p>
        </div>
        <Button
          onClick={() => {
            resetForm()
            setOpen(true)
          }}
        >
          {t('tokens.create')}
        </Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>{t('tokens.listTitle')}</CardTitle>
          <CardDescription>{t('tokens.listHint')}</CardDescription>
        </CardHeader>
        <CardContent>
          {tokens.isLoading ? (
            <p className="text-sm text-muted-foreground">{t('common.loading')}</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('common.name')}</TableHead>
                  <TableHead>{t('tokens.prefix')}</TableHead>
                  <TableHead>{t('tokens.grants')}</TableHead>
                  <TableHead>{t('common.status')}</TableHead>
                  <TableHead className="text-right">{t('common.actions')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {(tokens.data ?? []).length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={5} className="text-muted-foreground">
                      {t('tokens.empty')}
                    </TableCell>
                  </TableRow>
                ) : (
                  (tokens.data ?? []).map((token) => (
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
                        {token.revokedAt ? (
                          <Badge variant="destructive">{t('tokens.revoked')}</Badge>
                        ) : (
                          <Badge>{t('tokens.active')}</Badge>
                        )}
                      </TableCell>
                      <TableCell className="text-right">
                        {!token.revokedAt ? (
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
                        ) : null}
                      </TableCell>
                    </TableRow>
                  ))
                )}
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
            <DialogTitle>{t('tokens.createTitle')}</DialogTitle>
            <DialogDescription>{t('tokens.createHint')}</DialogDescription>
          </DialogHeader>

          {createdToken ? (
            <div className="space-y-3">
              <p className="text-sm">{t('tokens.copyOnce')}</p>
              <code className="block break-all rounded-md border bg-muted p-3 text-xs">
                {createdToken}
              </code>
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
                <Input
                  id="token-expires"
                  type="datetime-local"
                  value={expiresAt}
                  onChange={(e) => setExpiresAt(e.target.value)}
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
                    <select
                      className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
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
                    </select>
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

              {error ? <p className="text-sm text-destructive">{error}</p> : null}
              <div className="flex justify-end gap-2">
                <Button variant="outline" onClick={() => setOpen(false)}>
                  {t('common.cancel')}
                </Button>
                <Button disabled={!name.trim() || create.isPending} onClick={() => create.mutate()}>
                  {create.isPending ? t('common.creating') : t('common.create')}
                </Button>
              </div>
            </div>
          )}
        </DialogContent>
      </Dialog>
    </div>
  )
}
