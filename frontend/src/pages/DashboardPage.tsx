import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  Bar,
  BarChart,
  CartesianGrid,
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { Skeleton } from '@/components/ui/skeleton'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
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

interface SystemStats {
  resources: number
  records: number
  apiRequests: number
  apiKeys: number
}

interface TimeseriesPoint {
  date: string
  requests: number
  avgDurationMs: number
  errors: number
}

interface PathCount {
  path: string
  count: number
}

interface TimeseriesPayload {
  days: number
  series: TimeseriesPoint[]
  topPaths: PathCount[]
  topErrors: PathCount[]
}

interface AuditRow {
  id: number
  action: string
  entityType: string | null
  entityId: string | null
  createdAt: string
}

interface ApiErrorRow {
  id: number
  method: string
  path: string
  status: number
  durationMs: number
  apiKeyId: number | null
  ip: string | null
  createdAt: string
}

const DASHBOARD_DAYS = 14

export function DashboardPage() {
  const { t } = useI18n()
  const [errorPath, setErrorPath] = useState<string | null>(null)
  const [errorPage, setErrorPage] = useState(1)

  const statsQuery = useQuery({
    queryKey: ['system-stats'],
    queryFn: () => api<SystemStats>('/admin/api/system/stats'),
  })
  const timeseriesQuery = useQuery({
    queryKey: ['system-stats-timeseries', DASHBOARD_DAYS],
    queryFn: () =>
      api<TimeseriesPayload>(`/admin/api/system/stats/timeseries?days=${DASHBOARD_DAYS}`),
  })
  const auditQuery = useQuery({
    queryKey: ['dashboard-audit'],
    queryFn: () => apiPage<AuditRow>('/admin/api/logs/audit?page=1&limit=5'),
  })
  const errorDetailsQuery = useQuery({
    queryKey: ['dashboard-error-details', errorPath, errorPage, DASHBOARD_DAYS],
    enabled: errorPath !== null,
    queryFn: () => {
      const q = new URLSearchParams({
        page: String(errorPage),
        limit: '50',
        path: errorPath ?? '',
        minStatus: '400',
        days: String(DASHBOARD_DAYS),
      })
      return apiPage<ApiErrorRow>(`/admin/api/logs/api?${q}`)
    },
  })

  const stats = [
    { label: t('dashboard.resources'), value: statsQuery.data?.resources },
    { label: t('dashboard.records'), value: statsQuery.data?.records },
    { label: t('dashboard.apiRequests'), value: statsQuery.data?.apiRequests },
    { label: t('dashboard.apiKeys'), value: statsQuery.data?.apiKeys },
  ]

  const series = timeseriesQuery.data?.series ?? []
  const chartData = series.map((point) => ({
    ...point,
    label: point.date.slice(5),
  }))
  const days = timeseriesQuery.data?.days ?? DASHBOARD_DAYS
  const errorMeta = errorDetailsQuery.data?.meta

  function openErrorDetails(path: string) {
    setErrorPath(path)
    setErrorPage(1)
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">{t('dashboard.title')}</h1>
        <p className="text-sm text-muted-foreground">{t('dashboard.subtitle')}</p>
      </div>
      <div className="grid gap-4 md:grid-cols-4">
        {stats.map((item) => (
          <Card key={item.label}>
            <CardHeader>
              <CardTitle className="text-sm font-medium text-muted-foreground">
                {item.label}
              </CardTitle>
            </CardHeader>
            <CardContent className="text-3xl font-semibold">
              {statsQuery.isLoading ? <Skeleton className="h-8 w-16" /> : (item.value ?? '—')}
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="text-sm font-medium">{t('dashboard.requestsOverTime')}</CardTitle>
            <p className="text-xs text-muted-foreground">
              {t('dashboard.lastDays', { days: String(days) })}
            </p>
          </CardHeader>
          <CardContent className="h-64">
            {timeseriesQuery.isLoading ? (
              <Skeleton className="h-full w-full" />
            ) : (
              <ResponsiveContainer width="100%" height="100%">
                <LineChart data={chartData}>
                  <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                  <XAxis dataKey="label" tick={{ fontSize: 11 }} />
                  <YAxis tick={{ fontSize: 11 }} allowDecimals={false} />
                  <Tooltip />
                  <Legend />
                  <Line
                    type="monotone"
                    dataKey="requests"
                    name={t('dashboard.requests')}
                    stroke="var(--primary)"
                    strokeWidth={2}
                    dot={false}
                  />
                  <Line
                    type="monotone"
                    dataKey="errors"
                    name={t('dashboard.errors')}
                    stroke="var(--destructive)"
                    strokeWidth={2}
                    dot={false}
                  />
                </LineChart>
              </ResponsiveContainer>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-sm font-medium">{t('dashboard.avgDuration')}</CardTitle>
          </CardHeader>
          <CardContent className="h-64">
            {timeseriesQuery.isLoading ? (
              <Skeleton className="h-full w-full" />
            ) : (
              <ResponsiveContainer width="100%" height="100%">
                <LineChart data={chartData}>
                  <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                  <XAxis dataKey="label" tick={{ fontSize: 11 }} />
                  <YAxis tick={{ fontSize: 11 }} />
                  <Tooltip />
                  <Line
                    type="monotone"
                    dataKey="avgDurationMs"
                    name={t('dashboard.avgDuration')}
                    stroke="var(--primary)"
                    strokeWidth={2}
                    dot={false}
                  />
                </LineChart>
              </ResponsiveContainer>
            )}
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card>
          <CardHeader>
            <CardTitle className="text-sm font-medium">{t('dashboard.topPaths')}</CardTitle>
          </CardHeader>
          <CardContent className="h-56">
            {timeseriesQuery.isLoading ? (
              <Skeleton className="h-full w-full" />
            ) : (timeseriesQuery.data?.topPaths.length ?? 0) === 0 ? (
              <p className="text-sm text-muted-foreground">{t('dashboard.noData')}</p>
            ) : (
              <ResponsiveContainer width="100%" height="100%">
                <BarChart
                  data={timeseriesQuery.data?.topPaths ?? []}
                  layout="vertical"
                  margin={{ left: 8, right: 8 }}
                >
                  <XAxis type="number" hide />
                  <YAxis
                    type="category"
                    dataKey="path"
                    width={120}
                    tick={{ fontSize: 10 }}
                    tickFormatter={(v: string) => (v.length > 22 ? `${v.slice(0, 20)}…` : v)}
                  />
                  <Tooltip />
                  <Bar dataKey="count" fill="var(--primary)" radius={4} />
                </BarChart>
              </ResponsiveContainer>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-sm font-medium">{t('dashboard.topErrors')}</CardTitle>
            <p className="text-xs text-muted-foreground">{t('dashboard.topErrorsHint')}</p>
          </CardHeader>
          <CardContent className="h-56 overflow-y-auto">
            {timeseriesQuery.isLoading ? (
              <Skeleton className="h-full w-full" />
            ) : (timeseriesQuery.data?.topErrors.length ?? 0) === 0 ? (
              <p className="text-sm text-muted-foreground">{t('dashboard.noData')}</p>
            ) : (
              <ul className="space-y-1">
                {(timeseriesQuery.data?.topErrors ?? []).map((row) => (
                  <li key={row.path}>
                    <button
                      type="button"
                      className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left hover:bg-muted/60"
                      onClick={() => openErrorDetails(row.path)}
                    >
                      <span className="min-w-0 flex-1 truncate font-mono text-xs" title={row.path}>
                        {row.path}
                      </span>
                      <span className="shrink-0 text-xs font-medium text-destructive">
                        {row.count}
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-sm font-medium">{t('dashboard.recentActivity')}</CardTitle>
          </CardHeader>
          <CardContent>
            {auditQuery.isLoading ? (
              <div className="space-y-2">
                <Skeleton className="h-4 w-full" />
                <Skeleton className="h-4 w-5/6" />
                <Skeleton className="h-4 w-4/5" />
                <Skeleton className="h-4 w-3/4" />
                <Skeleton className="h-4 w-2/3" />
              </div>
            ) : (auditQuery.data?.data.length ?? 0) === 0 ? (
              <p className="text-sm text-muted-foreground">{t('dashboard.noData')}</p>
            ) : (
              <ul className="space-y-2 text-sm">
                {auditQuery.data?.data.map((row) => (
                  <li key={row.id} className="border-b border-border/60 pb-2 last:border-0">
                    <div className="font-medium">{row.action}</div>
                    <div className="text-xs text-muted-foreground">
                      {[row.entityType, row.entityId].filter(Boolean).join(' · ') || '—'}
                      {' · '}
                      {row.createdAt}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </div>

      {statsQuery.isError || timeseriesQuery.isError || auditQuery.isError ? (
        <p className="text-sm text-destructive">
          {(statsQuery.error instanceof Error && statsQuery.error.message) ||
            (timeseriesQuery.error instanceof Error && timeseriesQuery.error.message) ||
            (auditQuery.error instanceof Error && auditQuery.error.message) ||
            t('common.requestFailed')}
        </p>
      ) : null}

      <Dialog
        open={errorPath !== null}
        onOpenChange={(open) => {
          if (!open) setErrorPath(null)
        }}
      >
        <DialogContent className="max-h-[85vh] max-w-3xl overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{t('dashboard.errorDetailsTitle')}</DialogTitle>
            <DialogDescription>
              {t('dashboard.errorDetailsHint', {
                path: errorPath ?? '',
                days: String(days),
              })}
            </DialogDescription>
          </DialogHeader>

          {errorDetailsQuery.isLoading ? (
            <div className="space-y-2">
              <Skeleton className="h-8 w-full" />
              <Skeleton className="h-8 w-full" />
              <Skeleton className="h-8 w-full" />
            </div>
          ) : errorDetailsQuery.isError ? (
            <p className="text-sm text-destructive">
              {errorDetailsQuery.error instanceof Error
                ? errorDetailsQuery.error.message
                : t('common.requestFailed')}
            </p>
          ) : (
            <div className="space-y-4">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('logs.when')}</TableHead>
                    <TableHead>{t('logs.method')}</TableHead>
                    <TableHead>{t('common.status')}</TableHead>
                    <TableHead>{t('logs.ms')}</TableHead>
                    <TableHead>IP</TableHead>
                    <TableHead>{t('logs.token')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {(errorDetailsQuery.data?.data.length ?? 0) === 0 ? (
                    <TableRow>
                      <TableCell colSpan={6} className="text-muted-foreground">
                        {t('dashboard.noData')}
                      </TableCell>
                    </TableRow>
                  ) : (
                    errorDetailsQuery.data?.data.map((row) => (
                      <TableRow key={row.id}>
                        <TableCell className="whitespace-nowrap text-xs">{row.createdAt}</TableCell>
                        <TableCell className="font-mono text-xs">{row.method}</TableCell>
                        <TableCell className="text-xs font-medium text-destructive">
                          {row.status}
                        </TableCell>
                        <TableCell className="text-xs">{row.durationMs}</TableCell>
                        <TableCell className="font-mono text-xs">{row.ip ?? '—'}</TableCell>
                        <TableCell className="text-xs">{row.apiKeyId ?? '—'}</TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>

              {errorMeta ? (
                <div className="flex items-center justify-between text-sm text-muted-foreground">
                  <span>
                    {t('common.pageOfTotal', {
                      page: errorMeta.page,
                      totalPages: errorMeta.totalPages,
                      total: errorMeta.total,
                    })}
                  </span>
                  <div className="flex gap-2">
                    <Button
                      size="sm"
                      variant="outline"
                      disabled={errorPage <= 1}
                      onClick={() => setErrorPage((p) => Math.max(1, p - 1))}
                    >
                      {t('common.prev')}
                    </Button>
                    <Button
                      size="sm"
                      variant="outline"
                      disabled={errorPage >= errorMeta.totalPages}
                      onClick={() => setErrorPage((p) => p + 1)}
                    >
                      {t('common.next')}
                    </Button>
                  </div>
                </div>
              ) : null}
            </div>
          )}
        </DialogContent>
      </Dialog>
    </div>
  )
}
