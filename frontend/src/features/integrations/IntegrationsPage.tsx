import { clsx } from 'clsx'
import { useI18n } from '@/i18n'
import { EmailApisCard } from './EmailApisCard'
import { EmailPlaygroundCard } from './EmailPlaygroundCard'
import { EmailProviderCard } from './EmailProviderCard'
import styles from './IntegrationsPage.module.css'
import { useEmailApis } from './useEmailApis'
import { useEmailIntegration } from './useEmailIntegration'
import { useEmailPlayground } from './useEmailPlayground'

export function IntegrationsPage() {
  const { t } = useI18n()
  const integration = useEmailIntegration()
  const apis = useEmailApis()
  const playground = useEmailPlayground()

  return (
    <div className={clsx(styles.root)}>
      <div>
        <h1 className={clsx(styles.title)}>{t('integrations.title')}</h1>
        <p className={clsx(styles.subtitle)}>{t('integrations.description')}</p>
      </div>

      <EmailProviderCard integration={integration} />
      <EmailApisCard apis={apis} onUseInPlayground={playground.setPlaygroundPath} />
      <EmailPlaygroundCard playground={playground} pathOptions={apis.pathOptions} />
    </div>
  )
}
