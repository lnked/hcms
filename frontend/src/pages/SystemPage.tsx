import { useQuery } from '@tanstack/react-query'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { api } from '@/lib/api'
import type { SystemVersion } from '@/types/system'

export function SystemPage() {
  const query = useQuery({
    queryKey: ['system-version'],
    queryFn: () => api<SystemVersion>('/admin/api/system/version'),
  })
  const data = query.data

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-semibold">System</h1>
      <Card>
        <CardHeader>
          <CardTitle>Version</CardTitle>
        </CardHeader>
        <CardContent className="space-y-2 text-sm">
          <p>Current: {data?.current ?? '…'}</p>
          <p>Latest: {data?.latest ?? 'n/a'}</p>
          <p>Released: {data?.releasedAt ?? 'n/a'}</p>
          <p>Channel: {data?.channel ?? 'stable'}</p>
          <p>Update available: {data?.updateAvailable ? 'yes' : 'no'}</p>
        </CardContent>
      </Card>
    </div>
  )
}
