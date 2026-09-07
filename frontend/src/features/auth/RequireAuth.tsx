import { useEffect } from 'react'
import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Skeleton } from '@/components/ui/skeleton'
import { api, clearToken, getToken } from '@/lib/api'
import type { AuthUser } from '@/types/system'

export function RequireAuth() {
  const location = useLocation()
  const token = getToken()
  const me = useQuery({
    queryKey: ['auth-me', token],
    queryFn: () => api<AuthUser>('/admin/api/auth/me'),
    enabled: Boolean(token),
    retry: false,
    staleTime: 30_000,
  })

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
      <div
        className="flex min-h-svh flex-col items-center justify-center gap-3"
        role="status"
        aria-busy="true"
      >
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-4 w-32" />
      </div>
    )
  }

  return <Outlet />
}
