import { lazy } from 'react'
import { Navigate } from 'react-router-dom'
import { PageSkeleton } from '@/components/skeletons'
import { RequireSection } from '@/features/auth/RequireSection'
import { useAcl } from '@/hooks/useAcl'
import { homePath, resolveHomeSection } from '@/lib/rbac'

const DashboardPage = lazy(() =>
  import('@/features/dashboard/DashboardPage').then((m) => ({ default: m.DashboardPage })),
)

/**
 * `/` entry: open configured home section. Dashboard renders in place;
 * any other home redirects to its path.
 */
export function HomeLanding() {
  const { me, user } = useAcl()

  if (me.isLoading || !user) {
    return <PageSkeleton />
  }

  const home = resolveHomeSection(user)
  if (home !== 'dashboard') {
    return <Navigate to={homePath(user)} replace />
  }

  return (
    <RequireSection section="dashboard">
      <DashboardPage />
    </RequireSection>
  )
}
