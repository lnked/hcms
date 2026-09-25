import { clsx } from 'clsx'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { useI18n } from '@/i18n'
import styles from './TokenRestrictionsFields.module.css'

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
    <div className={clsx(styles.root)}>
      <div>
        <Label>{t('tokens.restrictions')}</Label>
        <p className={clsx(styles.introHint)}>{t('tokens.restrictionsHint')}</p>
      </div>

      <div className={clsx(styles.field)}>
        <Label htmlFor="token-origins">{t('tokens.allowedOrigins')}</Label>
        <Textarea
          id="token-origins"
          rows={3}
          value={originsText}
          onChange={(e) => onOriginsTextChange(e.target.value)}
        />
        <p className={clsx(styles.hint)}>{t('tokens.allowedOriginsHint')}</p>
      </div>

      <label className={clsx(styles.checkRow)}>
        <input
          type="checkbox"
          className={clsx(styles.checkInput)}
          checked={requireOrigin}
          onChange={(e) => onRequireOriginChange(e.target.checked)}
        />
        <span>
          {t('tokens.requireOrigin')}
          <span className={clsx(styles.hintBlock)}>{t('tokens.requireOriginHint')}</span>
        </span>
      </label>

      <div className={clsx(styles.field)}>
        <Label htmlFor="token-ips">{t('tokens.allowedIps')}</Label>
        <Textarea
          id="token-ips"
          rows={3}
          value={ipsText}
          onChange={(e) => onIpsTextChange(e.target.value)}
        />
        <p className={clsx(styles.hint)}>{t('tokens.allowedIpsHint')}</p>
      </div>
    </div>
  )
}
