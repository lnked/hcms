import { getToken } from '@/lib/api'

export const queryKeys = {
  auth: {
    me: (token: string | null = getToken()) => ['auth-me', token] as const,
    root: ['auth-me'] as const,
  },
  fieldTypes: ['field-types'] as const,
  resources: {
    all: ['resources'] as const,
    detail: (id: number) => ['resource', id] as const,
    fields: (id: number) => ['resource-fields', id] as const,
    entries: (id: number, params?: unknown) =>
      params === undefined
        ? (['resource-entries', id] as const)
        : (['resource-entries', id, params] as const),
  },
  integrations: {
    email: ['integrations-email'] as const,
    emailApis: ['integrations-email-apis'] as const,
  },
  webhooks: {
    list: ['webhooks'] as const,
    deliveries: (id: number) => ['webhooks', id, 'deliveries'] as const,
  },
  system: {
    version: ['system-version'] as const,
    stats: ['system-stats'] as const,
    updateStatus: ['update-status'] as const,
    updatePreview: (direction: 'upgrade' | 'downgrade', version: string | null) =>
      ['update-preview', direction, version] as const,
  },
  settings: {
    apiAccess: ['settings-api-access'] as const,
    adminBase: ['settings-admin-base'] as const,
    adminSections: ['settings-admin-sections'] as const,
    security: ['settings-security'] as const,
    graphql: ['settings-graphql'] as const,
  },
  media: {
    capabilities: ['media-capabilities'] as const,
  },
}
