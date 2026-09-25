import { Navigate, useLocation } from 'react-router-dom'
import { PageSkeleton } from '@/components/skeletons'
import { useAcl } from '@/hooks/useAcl'
import { homePath, sectionForPath, type AdminRole, type AdminSection } from '@/lib/rbac'
import type { ReactNode } from 'react'

export function RequireSection({
  section,
  minRole,
  children,
}: {
  section?: AdminSection
  minRole?: AdminRole
  children: ReactNode
}) {
  const { me, canSection, user } = useAcl()
  const location = useLocation()
  const resolved = section ?? sectionForPath(location.pathname)

  if (me.isLoading) {
    return <PageSkeleton />
  }

  if (resolved && !canSection(resolved, minRole)) {
    const fallback = homePath(user)
    const fallbackSection = sectionForPath(fallback)
    if (fallbackSection === resolved) {
      return <Navigate to="/settings/account" replace />
    }
    return <Navigate to={fallback} replace />
  }

  return <>{children}</>
}
