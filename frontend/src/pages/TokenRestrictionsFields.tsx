import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { useI18n } from '@/i18n'

export interface TokenRestrictions {
  allowedOrigins: string[]
  requireOrigin: boolean
  allowedIps: string[]
}

export function parseLines(text: string): string[] {
  return text
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean)
}

export function TokenRestrictionsFields({
  originsText,
  ipsText,
  requireOrigin,
  onOriginsTextChange,
  onIpsTextChange,
  onRequireOriginChange,
}: {
  originsText: string
  ipsText: string
  requireOrigin: boolean
  onOriginsTextChange: (value: string) => void
  onIpsTextChange: (value: string) => void
  onRequireOriginChange: (value: boolean) => void
}) {
  const { t } = useI18n()

  return (
    <div className="space-y-3 rounded-md border p-3">
      <div>
        <Label>{t('tokens.restrictions')}</Label>
        <p className="mt-1 text-xs text-muted-foreground">{t('tokens.restrictionsHint')}</p>
      </div>

      <div className="space-y-1.5">
        <Label htmlFor="token-origins">{t('tokens.allowedOrigins')}</Label>
        <Textarea
          id="token-origins"
          rows={3}
          value={originsText}
          onChange={(e) => onOriginsTextChange(e.target.value)}
        />
        <p className="text-xs text-muted-foreground">{t('tokens.allowedOriginsHint')}</p>
      </div>

      <label className="flex items-start gap-2 text-sm">
        <input
          type="checkbox"
          className="mt-1"
          checked={requireOrigin}
          onChange={(e) => onRequireOriginChange(e.target.checked)}
        />
        <span>
          {t('tokens.requireOrigin')}
          <span className="block text-xs text-muted-foreground">
            {t('tokens.requireOriginHint')}
          </span>
        </span>
      </label>

      <div className="space-y-1.5">
        <Label htmlFor="token-ips">{t('tokens.allowedIps')}</Label>
        <Textarea
          id="token-ips"
          rows={3}
          value={ipsText}
          onChange={(e) => onIpsTextChange(e.target.value)}
        />
        <p className="text-xs text-muted-foreground">{t('tokens.allowedIpsHint')}</p>
      </div>
    </div>
  )
}
