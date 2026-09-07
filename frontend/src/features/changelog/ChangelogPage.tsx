import { useQuery } from '@tanstack/react-query'
import { EmptyState } from '@/components/EmptyState'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { FormBlockSkeleton } from '@/components/skeletons'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import type { ChangeType, Release } from '@/types/system'

const typeVariant: Record<ChangeType, 'default' | 'secondary' | 'destructive' | 'outline'> = {
  added: 'default',
  changed: 'secondary',
  deprecated: 'outline',
  removed: 'outline',
  fixed: 'secondary',
  security: 'destructive',
  breaking: 'destructive',
}

export function ChangelogPage() {
  const { t } = useI18n()
  const query = useQuery({
    queryKey: ['changelog'],
    queryFn: () => api<Release[]>('/admin/api/system/changelog'),
  })

  const releases = query.data ?? []

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">{t('changelog.title')}</h1>
        <p className="text-sm text-muted-foreground">{t('changelog.subtitle')}</p>
      </div>
      {query.isLoading ? (
        <FormBlockSkeleton fields={4} />
      ) : query.isError ? (
        <EmptyState title={t('common.loadError')} description={t('changelog.loadError')} />
      ) : releases.length === 0 ? (
        <EmptyState title={t('changelog.empty')} />
      ) : (
        releases.map((release) => (
          <Card key={release.version}>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                v{release.version}
                {release.title ? (
                  <span className="text-muted-foreground">— {release.title}</span>
                ) : null}
                <Badge variant="outline">{release.channel}</Badge>
              </CardTitle>
            </CardHeader>
            <CardContent>
              <ul className="space-y-2">
                {release.changes.map((change) => (
                  <li key={change.text} className="flex items-start gap-2 text-sm">
                    <Badge variant={typeVariant[change.type]}>{change.type}</Badge>
                    <span>{change.text}</span>
                  </li>
                ))}
              </ul>
            </CardContent>
          </Card>
        ))
      )}
    </div>
  )
}
