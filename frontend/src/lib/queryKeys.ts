import { getToken } from '@/lib/api'

export const queryKeys = {
  auth: {
    me: (token: string | null = getToken()) => ['auth-me', token] as const,
    root: ['auth-me'] as const,
  },
  resources: {
    all: ['resources'] as const,
    detail: (id: number) => ['resource', id] as const,
    fields: (id: number) => ['resource-fields', id] as const,
    entries: (id: number, params?: unknown) =>
      params === undefined
        ? (['resource-entries', id] as const)
        : (['resource-entries', id, params] as const),
  },
  system: {
    version: ['system-version'] as const,
    stats: ['system-stats'] as const,
    updateStatus: ['update-status'] as const,
    updatePreview: ['update-preview'] as const,
  },
  settings: {
    apiAccess: ['settings-api-access'] as const,
  },
}
