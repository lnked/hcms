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
import { clsx } from 'clsx'
import { useI18n } from '@/i18n'
import styles from './DashboardCharts.module.css'

/**
 * recharts is the heaviest dependency in the admin bundle, so every chart lives
 * here and the dashboard pulls this module in lazily — the KPI cards and the
 * activity list render without waiting for it.
 */

export interface ChartPoint {
  label: string
  requests: number
  avgDurationMs: number
  errors: number
}

export interface PathCount {
  path: string
  count: number
}

const TICK = { fontSize: 11 }

export function RequestsChart({ data }: { data: ChartPoint[] }) {
  const { t } = useI18n()

  return (
    <ResponsiveContainer width="100%" height="100%">
      <LineChart data={data}>
        <CartesianGrid strokeDasharray="3 3" className={clsx(styles.gridStroke)} />
        <XAxis dataKey="label" tick={TICK} />
        <YAxis tick={TICK} allowDecimals={false} />
        <Tooltip />
        <Legend />
        <Line
          type="monotone"
          dataKey="requests"
          name={t('dashboard.requests')}
          stroke="var(--hcms-color-primary)"
          strokeWidth={2}
          dot={false}
        />
        <Line
          type="monotone"
          dataKey="errors"
          name={t('dashboard.errors')}
          stroke="var(--hcms-color-destructive)"
          strokeWidth={2}
          dot={false}
        />
      </LineChart>
    </ResponsiveContainer>
  )
}

export function DurationChart({ data }: { data: ChartPoint[] }) {
  const { t } = useI18n()

  return (
    <ResponsiveContainer width="100%" height="100%">
      <LineChart data={data}>
        <CartesianGrid strokeDasharray="3 3" className={clsx(styles.gridStroke)} />
        <XAxis dataKey="label" tick={TICK} />
        <YAxis tick={TICK} />
        <Tooltip />
        <Line
          type="monotone"
          dataKey="avgDurationMs"
          name={t('dashboard.avgDuration')}
          stroke="var(--hcms-color-primary)"
          strokeWidth={2}
          dot={false}
        />
      </LineChart>
    </ResponsiveContainer>
  )
}

export function TopPathsChart({ data }: { data: PathCount[] }) {
  return (
    <ResponsiveContainer width="100%" height="100%">
      <BarChart data={data} layout="vertical" margin={{ left: 8, right: 8 }}>
        <XAxis type="number" hide />
        <YAxis
          type="category"
          dataKey="path"
          width={120}
          tick={{ fontSize: 10 }}
          tickFormatter={(v: string) => (v.length > 22 ? `${v.slice(0, 20)}…` : v)}
        />
        <Tooltip />
        <Bar dataKey="count" fill="var(--hcms-color-primary)" radius={4} />
      </BarChart>
    </ResponsiveContainer>
  )
}
