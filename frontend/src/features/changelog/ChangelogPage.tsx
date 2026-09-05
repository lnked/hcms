import { useQuery } from '@tanstack/react-query'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
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
  const query = useQuery({
    queryKey: ['changelog'],
    queryFn: () => api<Release[]>('/admin/api/system/changelog'),
  })

  const releases = query.data ?? []

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">Changelog</h1>
        <p className="text-sm text-muted-foreground">Product releases of the admin CMS.</p>
      </div>
      {releases.map((release) => (
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
      ))}
    </div>
  )
}
