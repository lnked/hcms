export const WEBHOOK_EVENTS = [
  'entry.created',
  'entry.updated',
  'entry.deleted',
  'entry.submitted',
  'entry.published',
  'entry.unpublished',
  'resource.published',
] as const

export type WebhookEvent = (typeof WEBHOOK_EVENTS)[number]

export const WEBHOOK_PRESETS = [
  { id: 'custom', payloadMode: 'hcms' as const, events: ['entry.created'] as WebhookEvent[] },
  {
    id: 'vercel_deploy',
    payloadMode: 'empty' as const,
    events: [
      'entry.created',
      'entry.updated',
      'entry.deleted',
      'resource.published',
    ] as WebhookEvent[],
  },
  {
    id: 'netlify_build',
    payloadMode: 'empty' as const,
    events: [
      'entry.created',
      'entry.updated',
      'entry.deleted',
      'resource.published',
    ] as WebhookEvent[],
  },
  {
    id: 'cloudflare_purge',
    payloadMode: 'surrogate_keys' as const,
    events: ['entry.updated', 'entry.deleted', 'resource.published'] as WebhookEvent[],
  },
  {
    id: 'fastly_purge',
    payloadMode: 'surrogate_keys' as const,
    events: ['entry.updated', 'entry.deleted', 'resource.published'] as WebhookEvent[],
  },
] as const

export type WebhookPresetId = (typeof WEBHOOK_PRESETS)[number]['id']

export interface Webhook {
  id: number
  name: string
  url: string
  secret: string
  events: string[]
  resourceId: number | null
  status: 'active' | 'disabled'
  preset: string | null
  payloadMode: 'hcms' | 'empty' | 'surrogate_keys'
  headers: Record<string, string>
  createdAt: string
  updatedAt: string
}

export interface WebhookDelivery {
  id: number
  webhookId: number
  event: string
  payload: Record<string, unknown>
  responseCode: number | null
  durationMs: number | null
  attempt: number
  status: 'pending' | 'success' | 'failed'
  errorMessage: string | null
  createdAt: string
}

export function randomSecret(): string {
  const bytes = new Uint8Array(32)
  crypto.getRandomValues(bytes)
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
}
