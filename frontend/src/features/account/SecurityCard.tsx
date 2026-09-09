import { clsx } from 'clsx'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Separator } from '@/components/ui/separator'
import { ChangePasswordDialog } from '@/features/account/ChangePasswordDialog'
import { TotpSection } from '@/features/account/TotpSection'
import { useI18n } from '@/i18n'
import styles from './SecurityCard.module.css'

export function SecurityCard() {
  const { t } = useI18n()

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('account.securityTitle')}</CardTitle>
        <CardDescription>{t('account.securityHint')}</CardDescription>
      </CardHeader>
      <CardContent className={clsx(styles.content)}>
        <div className={clsx(styles.section)}>
          <p className={clsx(styles.title)}>{t('account.passwordTitle')}</p>
          <ChangePasswordDialog />
          <p className={clsx(styles.muted)}>{t('account.passwordAdvice')}</p>
        </div>

        <Separator />

        <TotpSection />
      </CardContent>
    </Card>
  )
}
