import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
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
import { apiPage } from '@/lib/api'

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

type Tab = 'audit' | 'api'

export function LogsPage() {
  const { t } = useI18n()
  const [tab, setTab] = useState<Tab>('audit')
  const [page, setPage] = useState(1)
  const [action, setAction] = useState('')
  const [actionFilter, setActionFilter] = useState('')

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

  const meta = tab === 'audit' ? audit.data?.meta : apiLogs.data?.meta

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">{t('logs.title')}</h1>
        <p className="text-sm text-muted-foreground">{t('logs.subtitle')}</p>
      </div>

      <div className="flex gap-2 border-b pb-2">
        {(['audit', 'api'] as Tab[]).map((item) => (
          <Button
            key={item}
            size="sm"
            variant={tab === item ? 'default' : 'ghost'}
            onClick={() => {
              setTab(item)
              setPage(1)
            }}
          >
            {item === 'audit' ? t('logs.audit') : t('logs.api')}
          </Button>
        ))}
      </div>

      <Card>
        <CardHeader>
          <CardTitle>{tab === 'audit' ? t('logs.auditTitle') : t('logs.apiTitle')}</CardTitle>
          <CardDescription>
            {tab === 'audit' ? t('logs.auditHint') : t('logs.apiHint')}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {tab === 'audit' ? (
            <form
              className="flex gap-2"
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
                className="max-w-sm"
              />
              <Button type="submit" variant="outline">
                {t('common.filter')}
              </Button>
            </form>
          ) : null}

          {tab === 'audit' ? (
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
                    <TableCell colSpan={5} className="text-muted-foreground">
                      {t('logs.noAudit')}
                    </TableCell>
                  </TableRow>
                ) : (
                  (audit.data?.data ?? []).map((row) => (
                    <TableRow key={row.id}>
                      <TableCell className="whitespace-nowrap text-xs">{row.createdAt}</TableCell>
                      <TableCell className="font-mono text-xs">{row.action}</TableCell>
                      <TableCell className="text-xs">
                        {row.entityType ?? '—'}
                        {row.entityId ? ` #${row.entityId}` : ''}
                      </TableCell>
                      <TableCell className="text-xs">{row.userId ?? '—'}</TableCell>
                      <TableCell className="text-xs">{row.ip ?? '—'}</TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
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
                    <TableCell colSpan={6} className="text-muted-foreground">
                      {t('logs.noApi')}
                    </TableCell>
                  </TableRow>
                ) : (
                  (apiLogs.data?.data ?? []).map((row) => (
                    <TableRow key={row.id}>
                      <TableCell className="whitespace-nowrap text-xs">{row.createdAt}</TableCell>
                      <TableCell className="font-mono text-xs">{row.method}</TableCell>
                      <TableCell className="font-mono text-xs">{row.path}</TableCell>
                      <TableCell className="text-xs">{row.status}</TableCell>
                      <TableCell className="text-xs">{row.durationMs}</TableCell>
                      <TableCell className="text-xs">{row.apiKeyId ?? '—'}</TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          )}

          {meta ? (
            <div className="flex items-center justify-between text-sm text-muted-foreground">
              <span>
                {t('common.pageOfTotal', {
                  page: meta.page,
                  totalPages: meta.totalPages,
                  total: meta.total,
                })}
              </span>
              <div className="flex gap-2">
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
    </div>
  )
}
