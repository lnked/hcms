import { useQuery } from '@tanstack/react-query'
import { Skeleton } from '@/components/ui/skeleton'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'

interface SystemStats {
  resources: number
  records: number
  apiRequests: number
  apiKeys: number
}

export function DashboardPage() {
  const { t } = useI18n()
  const statsQuery = useQuery({
    queryKey: ['system-stats'],
    queryFn: () => api<SystemStats>('/admin/api/system/stats'),
  })

  const stats = [
    { label: t('dashboard.resources'), value: statsQuery.data?.resources },
    { label: t('dashboard.records'), value: statsQuery.data?.records },
    { label: t('dashboard.apiRequests'), value: statsQuery.data?.apiRequests },
    { label: t('dashboard.apiKeys'), value: statsQuery.data?.apiKeys },
  ]

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
      {statsQuery.isError ? (
        <p className="text-sm text-destructive">
          {statsQuery.error instanceof Error ? statsQuery.error.message : t('common.requestFailed')}
        </p>
      ) : null}
    </div>
  )
}
