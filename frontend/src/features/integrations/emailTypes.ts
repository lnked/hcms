import type { MessageKey } from '@/i18n'

export type EmailProvider = 'resend' | 'postmark' | 'mailgun'
export type MailgunRegion = 'us' | 'eu'

export interface ProviderKeyStatus {
  apiKeyConfigured: boolean
  apiKeyMasked: string | null
}

export interface EmailIntegrationConfig {
  provider: EmailProvider
  enabled: boolean
  fromEmail: string
  fromName: string
  dailyQuota: number
  allowedRecipientDomains: string[]
  apiKeyConfigured: boolean
  apiKeyMasked: string | null
  mailgunDomain: string
  mailgunRegion: MailgunRegion
  providers: Record<EmailProvider, ProviderKeyStatus>
}

export interface EmailIntegrationApi {
  id: number
  integrationKey: string
  slug: string
  label: string
  enabled: boolean
  defaults: { subject: string; html: string; text: string }
  settings: { allowFromOverride: boolean }
  path: string
}

export interface EmailApiDraft {
  slug: string
  label: string
  enabled: boolean
  defaults: { subject: string; html: string; text: string }
  settings: { allowFromOverride: boolean }
}

export const PROVIDERS: Array<{ id: EmailProvider; title: string; descriptionKey: MessageKey }> = [
  { id: 'resend', title: 'Resend', descriptionKey: 'integrations.resend.description' },
  { id: 'postmark', title: 'Postmark', descriptionKey: 'integrations.postmark.description' },
  { id: 'mailgun', title: 'Mailgun', descriptionKey: 'integrations.mailgun.description' },
]

export const SEND_PATH = '/api/integrations/email/send'

export const DEFAULT_PLAYGROUND_BODY = `{
  "to": "you@example.com",
  "subject": "Hello from HCMS",
  "html": "<p>Hi {{name}}</p>",
  "vars": { "name": "Ada" }
}`

export function isProvider(value: string): value is EmailProvider {
  return value === 'resend' || value === 'postmark' || value === 'mailgun'
}

export function apiKeyPlaceholder(provider: EmailProvider): string {
  if (provider === 'postmark') return 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'
  if (provider === 'mailgun') return 'key-xxxxxxxx'
  return 're_xxxxxxxx'
}

export function emptyApiDraft(): EmailApiDraft {
  return {
    slug: '',
    label: '',
    enabled: true,
    defaults: { subject: '', html: '', text: '' },
    settings: { allowFromOverride: true },
  }
}
