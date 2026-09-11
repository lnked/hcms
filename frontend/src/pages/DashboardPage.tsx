import { lazy, Suspense, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { clsx } from 'clsx'
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
import { Link } from 'react-router-dom'
import { useI18n } from '@/i18n'
import { api, apiPage } from '@/lib/api'
import type { PathCount } from './DashboardCharts'
import styles from './DashboardPage.module.css'

// All three charts sit in one lazy module, so recharts costs a single request
// and stays out of the chunk that renders the KPI cards.
const RequestsChart = lazy(() =>
  import('./DashboardCharts').then((m) => ({ default: m.RequestsChart })),
)
const DurationChart = lazy(() =>
  import('./DashboardCharts').then((m) => ({ default: m.DurationChart })),
)
const TopPathsChart = lazy(() =>
  import('./DashboardCharts').then((m) => ({ default: m.TopPathsChart })),
)

const ChartFallback = <Skeleton className={clsx(styles.skeletonFill)} />

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

interface UptimeSummary {
  up: number
  down: number
  unknown: number
  total: number
  uptimePercent24h: number
  uptimePercent7d: number
  openIncidents: number
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
  const uptimeQuery = useQuery({
    queryKey: ['uptime-summary'],
    queryFn: () => api<UptimeSummary>('/admin/api/uptime/summary'),
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
    <div className={clsx(styles.root)}>
      <div>
        <h1 className={clsx(styles.title)}>{t('dashboard.title')}</h1>
        <p className={clsx(styles.subtitle)}>{t('dashboard.subtitle')}</p>
      </div>
      <div className={clsx(styles.kpiGrid)}>
        {stats.map((item) => (
          <Card key={item.label}>
            <CardHeader>
              <CardTitle className={clsx(styles.kpiLabel)}>{item.label}</CardTitle>
            </CardHeader>
            <CardContent className={clsx(styles.kpiValue)}>
              {statsQuery.isLoading ? (
                <Skeleton className={clsx(styles.skeletonKpi)} />
              ) : (
                (item.value ?? '—')
              )}
            </CardContent>
          </Card>
        ))}
      </div>

      <Card>
        <CardHeader>
          <CardTitle className={clsx(styles.cardTitle)}>{t('dashboard.uptime')}</CardTitle>
          <p className={clsx(styles.cardHint)}>
            <Link to="/settings/uptime" className={clsx(styles.uptimeLink)}>
              {t('dashboard.uptimeLink')}
            </Link>
          </p>
        </CardHeader>
        <CardContent>
          {uptimeQuery.isLoading ? (
            <Skeleton className={clsx(styles.skeletonKpi)} />
          ) : uptimeQuery.data ? (
            <div className={clsx(styles.uptimeRow)}>
              <span className={clsx(styles.kpiValue)}>
                {t('dashboard.uptimePercent', {
                  pct: String(uptimeQuery.data.uptimePercent24h),
                })}
              </span>
              <span className={clsx(styles.cardHint)}>
                {uptimeQuery.data.total === 0 || uptimeQuery.data.up + uptimeQuery.data.down === 0
                  ? t('dashboard.uptimeUnknown')
                  : t('dashboard.uptimeUpDown', {
                      up: String(uptimeQuery.data.up),
                      down: String(uptimeQuery.data.down),
                    })}
              </span>
            </div>
          ) : (
            '—'
          )}
        </CardContent>
      </Card>

      <div className={clsx(styles.chartsGrid)}>
        <Card>
          <CardHeader>
            <CardTitle className={clsx(styles.cardTitle)}>
              {t('dashboard.requestsOverTime')}
            </CardTitle>
            <p className={clsx(styles.cardHint)}>
              {t('dashboard.lastDays', { days: String(days) })}
            </p>
          </CardHeader>
          <CardContent className={clsx(styles.chartTall)}>
            {timeseriesQuery.isLoading ? (
              ChartFallback
            ) : (
              <Suspense fallback={ChartFallback}>
                <RequestsChart data={chartData} />
              </Suspense>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className={clsx(styles.cardTitle)}>{t('dashboard.avgDuration')}</CardTitle>
          </CardHeader>
          <CardContent className={clsx(styles.chartTall)}>
            {timeseriesQuery.isLoading ? (
              ChartFallback
            ) : (
              <Suspense fallback={ChartFallback}>
                <DurationChart data={chartData} />
              </Suspense>
            )}
          </CardContent>
        </Card>
      </div>

      <div className={clsx(styles.bottomGrid)}>
        <Card>
          <CardHeader>
            <CardTitle className={clsx(styles.cardTitle)}>{t('dashboard.topPaths')}</CardTitle>
          </CardHeader>
          <CardContent className={clsx(styles.chartShort)}>
            {timeseriesQuery.isLoading ? (
              ChartFallback
            ) : (timeseriesQuery.data?.topPaths.length ?? 0) === 0 ? (
              <p className={clsx(styles.muted)}>{t('dashboard.noData')}</p>
            ) : (
              <Suspense fallback={ChartFallback}>
                <TopPathsChart data={timeseriesQuery.data?.topPaths ?? []} />
              </Suspense>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className={clsx(styles.cardTitle)}>{t('dashboard.topErrors')}</CardTitle>
            <p className={clsx(styles.cardHint)}>{t('dashboard.topErrorsHint')}</p>
          </CardHeader>
          <CardContent className={clsx(styles.chartShortScroll)}>
            {timeseriesQuery.isLoading ? (
              <Skeleton className={clsx(styles.skeletonFill)} />
            ) : (timeseriesQuery.data?.topErrors.length ?? 0) === 0 ? (
              <p className={clsx(styles.muted)}>{t('dashboard.noData')}</p>
            ) : (
              <ul className={clsx(styles.errorList)}>
                {(timeseriesQuery.data?.topErrors ?? []).map((row) => (
                  <li key={row.path}>
                    <button
                      type="button"
                      className={clsx(styles.errorBtn)}
                      onClick={() => openErrorDetails(row.path)}
                    >
                      <span className={clsx(styles.errorPath)} title={row.path}>
                        {row.path}
                      </span>
                      <span className={clsx(styles.errorCount)}>{row.count}</span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className={clsx(styles.cardTitle)}>
              {t('dashboard.recentActivity')}
            </CardTitle>
          </CardHeader>
          <CardContent>
            {auditQuery.isLoading ? (
              <div className={clsx(styles.activitySkeleton)}>
                <Skeleton className={clsx(styles.skelFull)} />
                <Skeleton className={clsx(styles.skel5)} />
                <Skeleton className={clsx(styles.skel4)} />
                <Skeleton className={clsx(styles.skel3)} />
                <Skeleton className={clsx(styles.skel2)} />
              </div>
            ) : (auditQuery.data?.data.length ?? 0) === 0 ? (
              <p className={clsx(styles.muted)}>{t('dashboard.noData')}</p>
            ) : (
              <ul className={clsx(styles.activityList)}>
                {auditQuery.data?.data.map((row) => (
                  <li key={row.id} className={clsx(styles.activityItem)}>
                    <div className={clsx(styles.activityAction)}>{row.action}</div>
                    <div className={clsx(styles.activityMeta)}>
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
        <p className={clsx(styles.error)}>
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
        <DialogContent className={clsx(styles.dialogWide)}>
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
            <div className={clsx(styles.stack)}>
              <Skeleton className={clsx(styles.skelRow)} />
              <Skeleton className={clsx(styles.skelRow)} />
              <Skeleton className={clsx(styles.skelRow)} />
            </div>
          ) : errorDetailsQuery.isError ? (
            <p className={clsx(styles.error)}>
              {errorDetailsQuery.error instanceof Error
                ? errorDetailsQuery.error.message
                : t('common.requestFailed')}
            </p>
          ) : (
            <div className={clsx(styles.details)}>
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
                      <TableCell colSpan={6} className={clsx(styles.mutedCell)}>
                        {t('dashboard.noData')}
                      </TableCell>
                    </TableRow>
                  ) : (
                    errorDetailsQuery.data?.data.map((row) => (
                      <TableRow key={row.id}>
                        <TableCell className={clsx(styles.cellNowrap)}>{row.createdAt}</TableCell>
                        <TableCell className={clsx(styles.cellMono)}>{row.method}</TableCell>
                        <TableCell className={clsx(styles.cellStatus)}>{row.status}</TableCell>
                        <TableCell className={clsx(styles.cellXs)}>{row.durationMs}</TableCell>
                        <TableCell className={clsx(styles.cellMono)}>{row.ip ?? '—'}</TableCell>
                        <TableCell className={clsx(styles.cellXs)}>{row.apiKeyId ?? '—'}</TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>

              {errorMeta ? (
                <div className={clsx(styles.pager)}>
                  <span>
                    {t('common.pageOfTotal', {
                      page: errorMeta.page,
                      totalPages: errorMeta.totalPages,
                      total: errorMeta.total,
                    })}
                  </span>
                  <div className={clsx(styles.pagerBtns)}>
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
