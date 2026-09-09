import { useState } from 'react'
import { clsx } from 'clsx'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { TableSkeleton } from '@/components/skeletons'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { useI18n } from '@/i18n'
import { api, apiPage } from '@/lib/api'
import styles from './LogsPage.module.css'

interface AuditRow {
  id: number
  userId: number | null
  action: string
  entityType: string | null
  entityId: string | null
  ip: string | null
  createdAt: string
}

interface ApiRow {
  id: number
  method: string
  path: string
  status: number
  durationMs: number
  apiKeyId: number | null
  ip: string | null
  createdAt: string
}

interface IpCount {
  ip: string
  count: number
}

interface Anomalies {
  failedLogins: IpCount[]
  rateLimited: IpCount[]
  publicCreates: IpCount[]
  status429: IpCount[]
}

interface IpBlock {
  id: number
  ip: string
  reason: string
  expiresAt: string | null
  createdBy: number | null
  createdAt: string
}

type Tab = 'audit' | 'api' | 'security'

export function LogsPage() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [tab, setTab] = useState<Tab>('audit')
  const [page, setPage] = useState(1)
  const [action, setAction] = useState('')
  const [actionFilter, setActionFilter] = useState('')
  const [blockIp, setBlockIp] = useState('')
  const [blockReason, setBlockReason] = useState('manual')
  const [blockMessage, setBlockMessage] = useState<string | null>(null)

  const audit = useQuery({
    queryKey: ['logs-audit', page, actionFilter],
    enabled: tab === 'audit',
    queryFn: () => {
      const q = new URLSearchParams({ page: String(page), limit: '50' })
      if (actionFilter) q.set('action', actionFilter)
      return apiPage<AuditRow>(`/admin/api/logs/audit?${q}`)
    },
  })

  const apiLogs = useQuery({
    queryKey: ['logs-api', page],
    enabled: tab === 'api',
    queryFn: () => apiPage<ApiRow>(`/admin/api/logs/api?page=${page}&limit=50`),
  })

  const anomalies = useQuery({
    queryKey: ['logs-anomalies'],
    enabled: tab === 'security',
    queryFn: () => api<Anomalies>('/admin/api/logs/anomalies'),
  })

  const ipBlocks = useQuery({
    queryKey: ['logs-ip-blocks', page],
    enabled: tab === 'security',
    queryFn: () => apiPage<IpBlock>(`/admin/api/logs/ip-blocks?page=${page}&limit=50`),
  })

  const createBlock = useMutation({
    mutationFn: () =>
      api('/admin/api/logs/ip-blocks', {
        method: 'POST',
        body: JSON.stringify({ ip: blockIp, reason: blockReason, ttlSeconds: 3600 }),
      }),
    onSuccess: () => {
      setBlockIp('')
      setBlockMessage(t('logs.ipBlocked'))
      void queryClient.invalidateQueries({ queryKey: ['logs-ip-blocks'] })
      void queryClient.invalidateQueries({ queryKey: ['logs-anomalies'] })
    },
    onError: (err) => setBlockMessage(err instanceof Error ? err.message : t('common.saveFailed')),
  })

  const removeBlock = useMutation({
    mutationFn: (id: number) => api(`/admin/api/logs/ip-blocks/${id}`, { method: 'DELETE' }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['logs-ip-blocks'] })
    },
  })

  const meta =
    tab === 'audit' ? audit.data?.meta : tab === 'api' ? apiLogs.data?.meta : ipBlocks.data?.meta

  function renderIpTable(rows: IpCount[] | undefined, empty: string, loading?: boolean) {
    if (loading) {
      return <TableSkeleton columns={2} rows={4} />
    }
    if (!rows || rows.length === 0) {
      return <p className={clsx(styles.muted)}>{empty}</p>
    }
    return (
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>IP</TableHead>
            <TableHead>{t('logs.count')}</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((row) => (
            <TableRow key={row.ip}>
              <TableCell className={clsx(styles.monoXs)}>{row.ip}</TableCell>
              <TableCell className={clsx(styles.xs)}>{row.count}</TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    )
  }

  return (
    <div className={clsx(styles.root)}>
      <div>
        <h1 className={clsx(styles.title)}>{t('logs.title')}</h1>
        <p className={clsx(styles.subtitle)}>{t('logs.subtitle')}</p>
      </div>

      <div className={clsx(styles.tabs)}>
        {(['audit', 'api', 'security'] as Tab[]).map((item) => (
          <Button
            key={item}
            size="sm"
            variant={tab === item ? 'default' : 'ghost'}
            onClick={() => {
              setTab(item)
              setPage(1)
            }}
          >
            {item === 'audit'
              ? t('logs.audit')
              : item === 'api'
                ? t('logs.api')
                : t('logs.security')}
          </Button>
        ))}
      </div>

      {tab === 'security' ? (
        <div className={clsx(styles.section)}>
          <div className={clsx(styles.grid2)}>
            <Card>
              <CardHeader>
                <CardTitle>{t('logs.failedLogins')}</CardTitle>
              </CardHeader>
              <CardContent>
                {renderIpTable(
                  anomalies.data?.failedLogins,
                  t('logs.noAnomalies'),
                  anomalies.isLoading,
                )}
              </CardContent>
            </Card>
            <Card>
              <CardHeader>
                <CardTitle>{t('logs.status429')}</CardTitle>
              </CardHeader>
              <CardContent>
                {renderIpTable(
                  anomalies.data?.status429,
                  t('logs.noAnomalies'),
                  anomalies.isLoading,
                )}
              </CardContent>
            </Card>
            <Card>
              <CardHeader>
                <CardTitle>{t('logs.topApiIps')}</CardTitle>
              </CardHeader>
              <CardContent>
                {renderIpTable(
                  anomalies.data?.publicCreates,
                  t('logs.noAnomalies'),
                  anomalies.isLoading,
                )}
              </CardContent>
            </Card>
            <Card>
              <CardHeader>
                <CardTitle>{t('logs.autoBlocks')}</CardTitle>
              </CardHeader>
              <CardContent>
                {renderIpTable(
                  anomalies.data?.rateLimited,
                  t('logs.noAnomalies'),
                  anomalies.isLoading,
                )}
              </CardContent>
            </Card>
          </div>

          <Card>
            <CardHeader>
              <CardTitle>{t('logs.ipBlocks')}</CardTitle>
              <CardDescription>{t('logs.ipBlocksHint')}</CardDescription>
            </CardHeader>
            <CardContent className={clsx(styles.stackMd)}>
              <form
                className={clsx(styles.formRow)}
                onSubmit={(e) => {
                  e.preventDefault()
                  createBlock.mutate()
                }}
              >
                <Input
                  className={clsx(styles.maxXs)}
                  placeholder="1.2.3.4"
                  value={blockIp}
                  onChange={(e) => setBlockIp(e.target.value)}
                  required
                />
                <Input
                  className={clsx(styles.maxXs)}
                  placeholder={t('logs.reason')}
                  value={blockReason}
                  onChange={(e) => setBlockReason(e.target.value)}
                />
                <Button type="submit" disabled={createBlock.isPending}>
                  {t('logs.blockIp')}
                </Button>
              </form>
              {blockMessage ? <p className={clsx(styles.muted)}>{blockMessage}</p> : null}
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>IP</TableHead>
                    <TableHead>{t('logs.reason')}</TableHead>
                    <TableHead>{t('logs.expires')}</TableHead>
                    <TableHead />
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {(ipBlocks.data?.data ?? []).length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={4} className={clsx(styles.mutedCell)}>
                        {t('logs.noIpBlocks')}
                      </TableCell>
                    </TableRow>
                  ) : (
                    (ipBlocks.data?.data ?? []).map((row) => (
                      <TableRow key={row.id}>
                        <TableCell className={clsx(styles.monoXs)}>{row.ip}</TableCell>
                        <TableCell className={clsx(styles.xs)}>{row.reason}</TableCell>
                        <TableCell className={clsx(styles.xs)}>{row.expiresAt ?? '—'}</TableCell>
                        <TableCell>
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => removeBlock.mutate(row.id)}
                          >
                            {t('logs.unblock')}
                          </Button>
                        </TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </div>
      ) : (
        <Card>
          <CardHeader>
            <CardTitle>{tab === 'audit' ? t('logs.auditTitle') : t('logs.apiTitle')}</CardTitle>
            <CardDescription>
              {tab === 'audit' ? t('logs.auditHint') : t('logs.apiHint')}
            </CardDescription>
          </CardHeader>
          <CardContent className={clsx(styles.stackMd)}>
            {tab === 'audit' ? (
              <form
                className={clsx(styles.filterRow)}
                onSubmit={(e) => {
                  e.preventDefault()
                  setPage(1)
                  setActionFilter(action.trim())
                }}
              >
                <Input
                  placeholder={t('logs.filterAction')}
                  value={action}
                  onChange={(e) => setAction(e.target.value)}
                  className={clsx(styles.maxSm)}
                />
                <Button type="submit" variant="outline">
                  {t('common.filter')}
                </Button>
              </form>
            ) : null}

            {tab === 'audit' ? (
              audit.isLoading ? (
                <TableSkeleton columns={5} rows={8} />
              ) : (
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>{t('logs.when')}</TableHead>
                      <TableHead>{t('logs.action')}</TableHead>
                      <TableHead>{t('logs.entity')}</TableHead>
                      <TableHead>{t('logs.user')}</TableHead>
                      <TableHead>{t('logs.ip')}</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {(audit.data?.data ?? []).length === 0 ? (
                      <TableRow>
                        <TableCell colSpan={5} className={clsx(styles.mutedCell)}>
                          {t('logs.noAudit')}
                        </TableCell>
                      </TableRow>
                    ) : (
                      (audit.data?.data ?? []).map((row) => (
                        <TableRow key={row.id}>
                          <TableCell className={clsx(styles.cellNowrap)}>{row.createdAt}</TableCell>
                          <TableCell className={clsx(styles.monoXs)}>{row.action}</TableCell>
                          <TableCell className={clsx(styles.xs)}>
                            {row.entityType ?? '—'}
                            {row.entityId ? ` #${row.entityId}` : ''}
                          </TableCell>
                          <TableCell className={clsx(styles.xs)}>{row.userId ?? '—'}</TableCell>
                          <TableCell className={clsx(styles.xs)}>{row.ip ?? '—'}</TableCell>
                        </TableRow>
                      ))
                    )}
                  </TableBody>
                </Table>
              )
            ) : apiLogs.isLoading ? (
              <TableSkeleton columns={6} rows={8} />
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('logs.when')}</TableHead>
                    <TableHead>{t('logs.method')}</TableHead>
                    <TableHead>{t('logs.path')}</TableHead>
                    <TableHead>{t('common.status')}</TableHead>
                    <TableHead>{t('logs.ms')}</TableHead>
                    <TableHead>{t('logs.token')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {(apiLogs.data?.data ?? []).length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={6} className={clsx(styles.mutedCell)}>
                        {t('logs.noApi')}
                      </TableCell>
                    </TableRow>
                  ) : (
                    (apiLogs.data?.data ?? []).map((row) => (
                      <TableRow key={row.id}>
                        <TableCell className={clsx(styles.cellNowrap)}>{row.createdAt}</TableCell>
                        <TableCell className={clsx(styles.monoXs)}>{row.method}</TableCell>
                        <TableCell className={clsx(styles.monoXs)}>{row.path}</TableCell>
                        <TableCell className={clsx(styles.xs)}>{row.status}</TableCell>
                        <TableCell className={clsx(styles.xs)}>{row.durationMs}</TableCell>
                        <TableCell className={clsx(styles.xs)}>{row.apiKeyId ?? '—'}</TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>
            )}

            {meta ? (
              <div className={clsx(styles.pager)}>
                <span>
                  {t('common.pageOfTotal', {
                    page: meta.page,
                    totalPages: meta.totalPages,
                    total: meta.total,
                  })}
                </span>
                <div className={clsx(styles.pagerBtns)}>
                  <Button
                    size="sm"
                    variant="outline"
                    disabled={page <= 1}
                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                  >
                    {t('common.prev')}
                  </Button>
                  <Button
                    size="sm"
                    variant="outline"
                    disabled={page >= meta.totalPages}
                    onClick={() => setPage((p) => p + 1)}
                  >
                    {t('common.next')}
                  </Button>
                </div>
              </div>
            ) : null}
          </CardContent>
        </Card>
      )}
    </div>
  )
}
