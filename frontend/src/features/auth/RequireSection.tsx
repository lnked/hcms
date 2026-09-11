import { Navigate, useLocation } from 'react-router-dom'
import { PageSkeleton } from '@/components/skeletons'
import { useAcl } from '@/hooks/useAcl'
import { sectionForPath, type AdminRole, type AdminSection } from '@/lib/rbac'
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
  const { me, canSection } = useAcl()
  const location = useLocation()
  const resolved = section ?? sectionForPath(location.pathname)

  if (me.isLoading) {
    return <PageSkeleton />
  }

  if (resolved && !canSection(resolved, minRole)) {
    return <Navigate to="/settings/account" replace />
  }

  return <>{children}</>
}
