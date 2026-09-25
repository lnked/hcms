import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { clsx } from 'clsx'
import { HardDrive, Cloud } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { EmptyState } from '@/components/EmptyState'
import { FormBlockSkeleton, TableSkeleton } from '@/components/skeletons'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Form } from '@/components/ui/form'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { useAuthMe } from '@/hooks/useAcl'
import { useI18n } from '@/i18n'
import { api, getToken } from '@/lib/api'
import { resolveApiPath } from '@/lib/adminBase'
import { copyToClipboard } from '@/lib/clipboard'
import { showError, showSuccess } from '@/lib/toast'
import styles from './BackupsPage.module.css'

type Tab = 'backups' | 'cloud'
type OauthProvider = 'google' | 'yandex' | 'dropbox'
type Provider = OauthProvider | 'sftp'

interface BackupItem {
  id: string
  createdAt: string | null
  appVersion: string | null
  tables: string[]
  mediaBytes: number
  destinations: string[]
  hasZip: boolean
  sizeBytes: number
}

interface BackupStatus {
  state: string
  step?: string | null
  error?: string | null
  id?: string
}

interface OauthPublic {
  enabled: boolean
  clientId: string
  clientSecretConfigured: boolean
  clientSecretMasked: string | null
  connected: boolean
  accountLabel: string
  redirectUri: string
}

interface SftpPublic {
  enabled: boolean
  host: string
  port: number
  username: string
  auth: 'password' | 'privateKey'
  passwordConfigured: boolean
  passwordMasked: string | null
  privateKeyConfigured: boolean
  privateKeyMasked: string | null
  passphraseConfigured: boolean
  remotePath: string
  insecureHostKey: boolean
  timeoutSec: number
  connected: boolean
}

interface CloudConfig {
  retention: number
  providers: {
    google: OauthPublic
    yandex: OauthPublic
    dropbox: OauthPublic
    sftp: SftpPublic
  }
}

const OAUTH_PROVIDERS: OauthProvider[] = ['google', 'yandex', 'dropbox']
const ALL_PROVIDERS: Provider[] = ['google', 'yandex', 'dropbox', 'sftp']

function parseTab(value: string | null): Tab {
  return value === 'cloud' ? 'cloud' : 'backups'
}

function formatBytes(n: number): string {
  if (n < 1024) return `${n} B`
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`
  return `${(n / (1024 * 1024)).toFixed(1)} MB`
}

export function BackupsPage() {
  const { t } = useI18n()
  const me = useAuthMe()
  const isOwner = me.data?.role === 'owner'
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()
  const tab = parseTab(params.get('section'))

  const [pushTo, setPushTo] = useState<Provider[]>([])
  const [retention, setRetention] = useState(10)

  const listQuery = useQuery({
    queryKey: ['backups'],
    queryFn: () => api<BackupItem[]>('/admin/api/backups'),
  })

  const statusQuery = useQuery({
    queryKey: ['backups', 'status'],
    queryFn: () => api<BackupStatus>('/admin/api/backups/status'),
    refetchInterval: (q) => {
      const state = q.state.data?.state
      return state === 'running' || state === 'queued' ? 1500 : false
    },
  })

  const cloudQuery = useQuery({
    queryKey: ['backups', 'cloud'],
    queryFn: () => api<CloudConfig>('/admin/api/backups/cloud'),
  })

  useEffect(() => {
    if (cloudQuery.data) setRetention(cloudQuery.data.retention)
  }, [cloudQuery.data])

  useEffect(() => {
    const connected = params.get('connected')
    const error = params.get('error')
    if (connected) {
      showSuccess(t('backups.cloud.connected', { provider: connected }))
      void queryClient.invalidateQueries({ queryKey: ['backups', 'cloud'] })
      setParams(
        (prev) => {
          const next = new URLSearchParams(prev)
          next.delete('connected')
          next.delete('error')
          next.set('section', 'cloud')
          return next
        },
        { replace: true },
      )
    } else if (error) {
      showError(error)
      setParams(
        (prev) => {
          const next = new URLSearchParams(prev)
          next.delete('error')
          next.set('section', 'cloud')
          return next
        },
        { replace: true },
      )
    }
  }, [params, queryClient, setParams, t])

  useEffect(() => {
    if (statusQuery.data?.state === 'done') {
      void queryClient.invalidateQueries({ queryKey: ['backups'] })
    }
  }, [statusQuery.data?.state, queryClient])

  const createMutation = useMutation({
    mutationFn: () =>
      api('/admin/api/backups', { method: 'POST', body: JSON.stringify({ pushTo }) }),
    onSuccess: () => {
      showSuccess(t('backups.createQueued'))
      void queryClient.invalidateQueries({ queryKey: ['backups', 'status'] })
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (id: string) => api(`/admin/api/backups/${id}`, { method: 'DELETE' }),
    onSuccess: () => {
      showSuccess(t('backups.deleted'))
      void queryClient.invalidateQueries({ queryKey: ['backups'] })
    },
  })

  const restoreMutation = useMutation({
    mutationFn: (id: string) =>
      api(`/admin/api/backups/${id}/restore`, {
        method: 'POST',
        body: JSON.stringify({ confirm: true }),
      }),
    onSuccess: () => {
      showSuccess(t('backups.restored'))
      void queryClient.invalidateQueries({ queryKey: ['backups'] })
    },
  })

  const busy = statusQuery.data?.state === 'running' || statusQuery.data?.state === 'queued'

  const connectedSet = useMemo(() => {
    const set = new Set<Provider>()
    const p = cloudQuery.data?.providers
    if (!p) return set
    for (const id of ALL_PROVIDERS) {
      if (p[id]?.connected) set.add(id)
    }
    return set
  }, [cloudQuery.data])

  return (
    <div className={styles.root}>
      <h1 className={styles.title}>{t('backups.title')}</h1>
      <p className={styles.hint}>{t('backups.hint')}</p>

      <div className={styles.tabs}>
        <button
          type="button"
          className={clsx(styles.tab, tab === 'backups' && styles.tabActive)}
          onClick={() => setParams({ section: 'backups' })}
        >
          <HardDrive className={styles.tabIcon} />
          {t('backups.tab.backups')}
        </button>
        <button
          type="button"
          className={clsx(styles.tab, tab === 'cloud' && styles.tabActive)}
          onClick={() => setParams({ section: 'cloud' })}
        >
          <Cloud className={styles.tabIcon} />
          {t('backups.tab.cloud')}
        </button>
      </div>

      {tab === 'backups' ? (
        <Card>
          <CardHeader>
            <CardTitle>{t('backups.listTitle')}</CardTitle>
            <CardDescription>{t('backups.listHint')}</CardDescription>
          </CardHeader>
          <CardContent className={styles.stack}>
            <div className={styles.pushRow}>
              <Label>{t('backups.pushTo')}</Label>
              <div className={styles.chipRow}>
                {ALL_PROVIDERS.map((id) => (
                  <label key={id} className={styles.chip}>
                    <input
                      type="checkbox"
                      disabled={!connectedSet.has(id)}
                      checked={pushTo.includes(id)}
                      onChange={() =>
                        setPushTo((prev) =>
                          prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id],
                        )
                      }
                    />
                    {t(`backups.provider.${id}`)}
                  </label>
                ))}
              </div>
              <Button
                type="button"
                disabled={busy || createMutation.isPending}
                onClick={() => createMutation.mutate()}
              >
                {busy ? t('backups.creating') : t('backups.create')}
              </Button>
            </div>

            {busy ? (
              <p className={styles.status}>
                {t('backups.status', {
                  state: statusQuery.data?.state ?? '',
                  step: statusQuery.data?.step ?? '',
                })}
              </p>
            ) : null}
            {statusQuery.data?.state === 'failed' && statusQuery.data.error ? (
              <p className={styles.error}>{statusQuery.data.error}</p>
            ) : null}

            {listQuery.isLoading ? (
              <TableSkeleton rows={4} />
            ) : listQuery.data && listQuery.data.length > 0 ? (
              <div className={styles.tableWrap}>
                <table className={styles.table}>
                  <thead>
                    <tr>
                      <th>{t('backups.col.id')}</th>
                      <th>{t('backups.col.created')}</th>
                      <th>{t('backups.col.size')}</th>
                      <th>{t('backups.col.destinations')}</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {listQuery.data.map((item) => (
                      <tr key={item.id}>
                        <td>
                          <code>{item.id}</code>
                          {item.appVersion ? (
                            <Badge className={styles.badge}>v{item.appVersion}</Badge>
                          ) : null}
                        </td>
                        <td>{item.createdAt ?? '—'}</td>
                        <td>{formatBytes(item.sizeBytes)}</td>
                        <td>
                          {item.destinations.length > 0
                            ? item.destinations.join(', ')
                            : t('backups.localOnly')}
                        </td>
                        <td className={styles.actions}>
                          <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => downloadBackup(item.id)}
                          >
                            {t('backups.download')}
                          </Button>
                          <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={!isOwner || restoreMutation.isPending}
                            title={isOwner ? undefined : t('backups.ownerOnlyRestore')}
                            onClick={() => {
                              if (confirm(t('backups.restoreConfirm', { id: item.id }))) {
                                restoreMutation.mutate(item.id)
                              }
                            }}
                          >
                            {t('backups.restore')}
                          </Button>
                          <Button
                            type="button"
                            size="sm"
                            variant="destructive"
                            disabled={deleteMutation.isPending}
                            onClick={() => {
                              if (confirm(t('backups.deleteConfirm', { id: item.id }))) {
                                deleteMutation.mutate(item.id)
                              }
                            }}
                          >
                            {t('backups.delete')}
                          </Button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <EmptyState title={t('backups.empty')} description={t('backups.emptyHint')} />
            )}
          </CardContent>
        </Card>
      ) : cloudQuery.isLoading || !cloudQuery.data ? (
        <FormBlockSkeleton />
      ) : (
        <div className={styles.cloudGrid}>
          <Card>
            <CardHeader>
              <CardTitle>{t('backups.retentionTitle')}</CardTitle>
              <CardDescription>{t('backups.retentionHint')}</CardDescription>
            </CardHeader>
            <CardContent>
              <Form
                onSubmit={() => {
                  void api('/admin/api/backups/cloud', {
                    method: 'PUT',
                    body: JSON.stringify({ retention }),
                  }).then(() => {
                    showSuccess(t('backups.saved'))
                    void queryClient.invalidateQueries({ queryKey: ['backups', 'cloud'] })
                  })
                }}
              >
                <Label htmlFor="retention">{t('backups.retention')}</Label>
                <Input
                  id="retention"
                  type="number"
                  min={1}
                  max={100}
                  value={retention}
                  onChange={(e) => setRetention(Number(e.target.value) || 10)}
                />
                <Button type="submit">{t('backups.save')}</Button>
              </Form>
            </CardContent>
          </Card>

          {OAUTH_PROVIDERS.map((provider) => (
            <OauthCard
              key={provider}
              provider={provider}
              data={cloudQuery.data.providers[provider]}
              onChanged={() =>
                void queryClient.invalidateQueries({ queryKey: ['backups', 'cloud'] })
              }
            />
          ))}

          <SftpCard
            data={cloudQuery.data.providers.sftp}
            onChanged={() => void queryClient.invalidateQueries({ queryKey: ['backups', 'cloud'] })}
          />
        </div>
      )}
    </div>
  )
}

function downloadBackup(id: string) {
  const token = getToken()
  void fetch(resolveApiPath(`/admin/api/backups/${id}/download`), {
    headers: token ? { Authorization: `Bearer ${token}` } : {},
  })
    .then(async (res) => {
      if (!res.ok) throw new Error('Download failed')
      const blob = await res.blob()
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `${id}.zip`
      a.click()
      URL.revokeObjectURL(url)
    })
    .catch((err: unknown) => showError(err instanceof Error ? err.message : 'Download failed'))
}

function OauthCard({
  provider,
  data,
  onChanged,
}: {
  provider: OauthProvider
  data: OauthPublic
  onChanged: () => void
}) {
  const { t } = useI18n()
  const [enabled, setEnabled] = useState(data.enabled)
  const [clientId, setClientId] = useState(data.clientId)
  const [clientSecret, setClientSecret] = useState('')

  useEffect(() => {
    setEnabled(data.enabled)
    setClientId(data.clientId)
  }, [data])

  const save = useMutation({
    mutationFn: () =>
      api('/admin/api/backups/cloud', {
        method: 'PUT',
        body: JSON.stringify({
          [provider]: {
            enabled,
            clientId,
            ...(clientSecret !== '' ? { clientSecret } : {}),
          },
        }),
      }),
    onSuccess: () => {
      showSuccess(t('backups.saved'))
      setClientSecret('')
      onChanged()
    },
  })

  const connect = useMutation({
    mutationFn: () =>
      api<{ url: string }>(`/admin/api/backups/cloud/${provider}/connect`, {
        method: 'POST',
        body: '{}',
      }),
    onSuccess: (res) => {
      window.location.href = res.url
    },
  })

  const disconnect = useMutation({
    mutationFn: () =>
      api(`/admin/api/backups/cloud/${provider}/disconnect`, { method: 'POST', body: '{}' }),
    onSuccess: () => {
      showSuccess(t('backups.cloud.disconnected'))
      onChanged()
    },
  })

  const test = useMutation({
    mutationFn: () =>
      api(`/admin/api/backups/cloud/${provider}/test`, { method: 'POST', body: '{}' }),
    onSuccess: () => showSuccess(t('backups.cloud.testOk')),
  })

  return (
    <Card>
      <CardHeader>
        <CardTitle className={styles.cardTitleRow}>
          {t(`backups.provider.${provider}`)}
          {data.connected ? (
            <Badge>{t('backups.cloud.statusConnected')}</Badge>
          ) : (
            <Badge variant="secondary">{t('backups.cloud.statusDisconnected')}</Badge>
          )}
        </CardTitle>
        <CardDescription>
          {data.connected && data.accountLabel
            ? t('backups.cloud.account', { label: data.accountLabel })
            : t(`backups.cloud.hint.${provider}`)}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <Form className={styles.stack} onSubmit={() => save.mutate()}>
          <label className={styles.chip}>
            <input
              type="checkbox"
              checked={enabled}
              onChange={(e) => setEnabled(e.target.checked)}
            />
            {t('backups.cloud.enabled')}
          </label>
          <div>
            <Label>{t('backups.cloud.clientId')}</Label>
            <Input value={clientId} onChange={(e) => setClientId(e.target.value)} />
          </div>
          <div>
            <Label>{t('backups.cloud.clientSecret')}</Label>
            <Input
              type="password"
              value={clientSecret}
              placeholder={
                data.clientSecretConfigured
                  ? t('backups.cloud.secretKeep', { masked: data.clientSecretMasked ?? '' })
                  : t('backups.cloud.secretPlaceholder')
              }
              onChange={(e) => setClientSecret(e.target.value)}
            />
          </div>
          <div>
            <Label>{t('backups.cloud.redirectUri')}</Label>
            <div className={styles.copyRow}>
              <Input readOnly value={data.redirectUri} />
              <Button
                type="button"
                variant="outline"
                onClick={() => {
                  void copyToClipboard(data.redirectUri).then(() =>
                    showSuccess(t('backups.cloud.copied')),
                  )
                }}
              >
                {t('backups.cloud.copy')}
              </Button>
            </div>
          </div>
          <div className={styles.actions}>
            <Button type="submit" disabled={save.isPending}>
              {t('backups.save')}
            </Button>
            <Button
              type="button"
              disabled={connect.isPending || !clientId}
              onClick={() => connect.mutate()}
            >
              {t('backups.cloud.connect')}
            </Button>
            {data.connected ? (
              <>
                <Button
                  type="button"
                  variant="outline"
                  disabled={test.isPending}
                  onClick={() => test.mutate()}
                >
                  {t('backups.cloud.test')}
                </Button>
                <Button
                  type="button"
                  variant="destructive"
                  disabled={disconnect.isPending}
                  onClick={() => disconnect.mutate()}
                >
                  {t('backups.cloud.disconnect')}
                </Button>
              </>
            ) : null}
          </div>
        </Form>
      </CardContent>
    </Card>
  )
}

function SftpCard({ data, onChanged }: { data: SftpPublic; onChanged: () => void }) {
  const { t } = useI18n()
  const [enabled, setEnabled] = useState(data.enabled)
  const [host, setHost] = useState(data.host)
  const [port, setPort] = useState(data.port)
  const [username, setUsername] = useState(data.username)
  const [auth, setAuth] = useState<'password' | 'privateKey'>(data.auth)
  const [password, setPassword] = useState('')
  const [privateKey, setPrivateKey] = useState('')
  const [passphrase, setPassphrase] = useState('')
  const [remotePath, setRemotePath] = useState(data.remotePath)
  const [insecureHostKey, setInsecureHostKey] = useState(data.insecureHostKey)

  useEffect(() => {
    setEnabled(data.enabled)
    setHost(data.host)
    setPort(data.port)
    setUsername(data.username)
    setAuth(data.auth)
    setRemotePath(data.remotePath)
    setInsecureHostKey(data.insecureHostKey)
  }, [data])

  const save = useMutation({
    mutationFn: () =>
      api('/admin/api/backups/cloud', {
        method: 'PUT',
        body: JSON.stringify({
          sftp: {
            enabled,
            host,
            port,
            username,
            auth,
            remotePath,
            insecureHostKey,
            ...(password !== '' ? { password } : {}),
            ...(privateKey !== '' ? { privateKey } : {}),
            ...(passphrase !== '' ? { passphrase } : {}),
          },
        }),
      }),
    onSuccess: () => {
      showSuccess(t('backups.saved'))
      setPassword('')
      setPrivateKey('')
      setPassphrase('')
      onChanged()
    },
  })

  const test = useMutation({
    mutationFn: () => api('/admin/api/backups/cloud/sftp/test', { method: 'POST', body: '{}' }),
    onSuccess: () => showSuccess(t('backups.cloud.testOk')),
  })

  const disconnect = useMutation({
    mutationFn: () =>
      api('/admin/api/backups/cloud/sftp/disconnect', { method: 'POST', body: '{}' }),
    onSuccess: () => {
      showSuccess(t('backups.cloud.disconnected'))
      onChanged()
    },
  })

  return (
    <Card>
      <CardHeader>
        <CardTitle className={styles.cardTitleRow}>
          {t('backups.provider.sftp')}
          {data.connected ? (
            <Badge>{t('backups.cloud.statusConnected')}</Badge>
          ) : (
            <Badge variant="secondary">{t('backups.cloud.statusDisconnected')}</Badge>
          )}
        </CardTitle>
        <CardDescription>{t('backups.cloud.hint.sftp')}</CardDescription>
      </CardHeader>
      <CardContent>
        <Form className={styles.stack} onSubmit={() => save.mutate()}>
          <label className={styles.chip}>
            <input
              type="checkbox"
              checked={enabled}
              onChange={(e) => setEnabled(e.target.checked)}
            />
            {t('backups.cloud.enabled')}
          </label>
          <div className={styles.grid2}>
            <div>
              <Label>{t('backups.sftp.host')}</Label>
              <Input value={host} onChange={(e) => setHost(e.target.value)} />
            </div>
            <div>
              <Label>{t('backups.sftp.port')}</Label>
              <Input
                type="number"
                value={port}
                onChange={(e) => setPort(Number(e.target.value) || 22)}
              />
            </div>
          </div>
          <div>
            <Label>{t('backups.sftp.username')}</Label>
            <Input value={username} onChange={(e) => setUsername(e.target.value)} />
          </div>
          <div>
            <Label>{t('backups.sftp.auth')}</Label>
            <Select
              value={auth}
              onChange={(e) => setAuth(e.target.value === 'privateKey' ? 'privateKey' : 'password')}
            >
              <option value="password">{t('backups.sftp.authPassword')}</option>
              <option value="privateKey">{t('backups.sftp.authKey')}</option>
            </Select>
          </div>
          {auth === 'password' ? (
            <div>
              <Label>{t('backups.sftp.password')}</Label>
              <Input
                type="password"
                value={password}
                placeholder={
                  data.passwordConfigured
                    ? t('backups.cloud.secretKeep', { masked: data.passwordMasked ?? '' })
                    : ''
                }
                onChange={(e) => setPassword(e.target.value)}
              />
            </div>
          ) : (
            <>
              <div>
                <Label>{t('backups.sftp.privateKey')}</Label>
                <Textarea
                  value={privateKey}
                  rows={4}
                  placeholder={
                    data.privateKeyConfigured
                      ? t('backups.cloud.secretKeep', { masked: data.privateKeyMasked ?? '' })
                      : '-----BEGIN OPENSSH PRIVATE KEY-----'
                  }
                  onChange={(e) => setPrivateKey(e.target.value)}
                />
              </div>
              <div>
                <Label>{t('backups.sftp.passphrase')}</Label>
                <Input
                  type="password"
                  value={passphrase}
                  onChange={(e) => setPassphrase(e.target.value)}
                />
              </div>
            </>
          )}
          <div>
            <Label>{t('backups.sftp.remotePath')}</Label>
            <Input value={remotePath} onChange={(e) => setRemotePath(e.target.value)} />
          </div>
          <label className={styles.chip}>
            <input
              type="checkbox"
              checked={insecureHostKey}
              onChange={(e) => setInsecureHostKey(e.target.checked)}
            />
            {t('backups.sftp.insecureHostKey')}
          </label>
          <div className={styles.actions}>
            <Button type="submit" disabled={save.isPending}>
              {t('backups.save')}
            </Button>
            <Button
              type="button"
              variant="outline"
              disabled={test.isPending}
              onClick={() => test.mutate()}
            >
              {t('backups.cloud.test')}
            </Button>
            {data.connected ? (
              <Button
                type="button"
                variant="destructive"
                disabled={disconnect.isPending}
                onClick={() => disconnect.mutate()}
              >
                {t('backups.cloud.disconnect')}
              </Button>
            ) : null}
          </div>
        </Form>
      </CardContent>
    </Card>
  )
}
