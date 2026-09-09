import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Separator } from '@/components/ui/separator'
import { ChangePasswordDialog } from '@/features/account/ChangePasswordDialog'
import { TotpSection } from '@/features/account/TotpSection'
import { useI18n } from '@/i18n'

export function SecurityCard() {
  const { t } = useI18n()

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('account.securityTitle')}</CardTitle>
        <CardDescription>{t('account.securityHint')}</CardDescription>
      </CardHeader>
      <CardContent className="space-y-6">
        <div className="space-y-2">
          <p className="text-sm font-medium">{t('account.passwordTitle')}</p>
          <ChangePasswordDialog />
          <p className="text-sm text-muted-foreground">{t('account.passwordAdvice')}</p>
        </div>

        <Separator />

        <TotpSection />
      </CardContent>
    </Card>
  )
}
