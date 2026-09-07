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
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
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

export function DashboardPage() {
  const { t } = useI18n()
  const statsQuery = useQuery({
    queryKey: ['system-stats'],
    queryFn: () => api<SystemStats>('/admin/api/system/stats'),
  })
  const timeseriesQuery = useQuery({
    queryKey: ['system-stats-timeseries', 14],
    queryFn: () => api<TimeseriesPayload>('/admin/api/system/stats/timeseries?days=14'),
  })
  const auditQuery = useQuery({
    queryKey: ['dashboard-audit'],
    queryFn: () => apiPage<AuditRow>('/admin/api/logs/audit?page=1&limit=5'),
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
              {t('dashboard.lastDays', { days: String(timeseriesQuery.data?.days ?? 14) })}
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
          </CardHeader>
          <CardContent className="h-56">
            {timeseriesQuery.isLoading ? (
              <Skeleton className="h-full w-full" />
            ) : (timeseriesQuery.data?.topErrors.length ?? 0) === 0 ? (
              <p className="text-sm text-muted-foreground">{t('dashboard.noData')}</p>
            ) : (
              <ResponsiveContainer width="100%" height="100%">
                <BarChart
                  data={timeseriesQuery.data?.topErrors ?? []}
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
                  <Bar dataKey="count" fill="var(--destructive)" radius={4} />
                </BarChart>
              </ResponsiveContainer>
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
    </div>
  )
}
