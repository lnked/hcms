import { useEffect } from 'react'
import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { Skeleton } from '@/components/ui/skeleton'
import { useAuthMe } from '@/hooks/useAcl'
import { clearToken, getToken } from '@/lib/api'
import styles from './RequireAuth.module.css'

export function RequireAuth() {
  const location = useLocation()
  const token = getToken()
  const me = useAuthMe({ enabled: Boolean(token), retry: false })

  useEffect(() => {
    if (me.isError) {
      clearToken()
    }
  }, [me.isError])

  if (!token || me.isError) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />
  }

  // Only block on initial load — background refetch must not unmount Outlet
  // (UsersPage shares auth-me; remount+refetch would loop forever).
  if (!me.data && (me.isLoading || me.isPending)) {
    return (
      <div className={styles.root} role="status" aria-busy="true">
        <Skeleton className={styles.title} />
        <Skeleton className={styles.subtitle} />
      </div>
    )
  }

  return <Outlet />
}
