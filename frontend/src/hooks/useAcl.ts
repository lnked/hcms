import { useQuery } from '@tanstack/react-query'
import { api, getToken } from '@/lib/api'
import type { AuthUser } from '@/types/system'
import {
  allowsResourceAction,
  allowsResourceTab,
  canAccessNav,
  type AdminRole,
  type AdminSection,
  type ResourceTab,
} from '@/lib/rbac'

export function useAuthMe() {
  return useQuery({
    queryKey: ['auth-me', getToken()],
    queryFn: () => api<AuthUser>('/admin/api/auth/me'),
    staleTime: 30_000,
  })
}

export function useAcl() {
  const me = useAuthMe()
  const user = me.data

  return {
    me,
    user,
    isOwner: user?.role === 'owner',
    canSection: (section: AdminSection, minRole?: AdminRole) =>
      canAccessNav(user, section, minRole),
    canResourceTab: (resourceId: number, tab: ResourceTab) =>
      allowsResourceTab(user, resourceId, tab),
    canResourceAction: (resourceId: number, action: 'read' | 'create' | 'update' | 'delete') =>
      allowsResourceAction(user, resourceId, action),
    aclEnabled: Boolean(user?.aclEnabled),
  }
}
