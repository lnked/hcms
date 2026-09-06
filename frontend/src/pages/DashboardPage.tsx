import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useI18n } from '@/i18n'

export function DashboardPage() {
  const { t } = useI18n()
  const stats = [
    { label: t('dashboard.resources'), value: '0' },
    { label: t('dashboard.records'), value: '0' },
    { label: t('dashboard.apiRequests'), value: '0' },
    { label: t('dashboard.apiKeys'), value: '0' },
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
            <CardContent className="text-3xl font-semibold">{item.value}</CardContent>
          </Card>
        ))}
      </div>
    </div>
  )
}
