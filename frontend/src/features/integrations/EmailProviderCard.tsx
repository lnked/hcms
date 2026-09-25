import { clsx } from 'clsx'
import { FormBlockSkeleton } from '@/components/skeletons'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Form } from '@/components/ui/form'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { useI18n } from '@/i18n'
import { apiKeyPlaceholder, PROVIDERS } from './emailTypes'
import styles from './IntegrationsPage.module.css'
import type { useEmailIntegration } from './useEmailIntegration'

type EmailIntegration = ReturnType<typeof useEmailIntegration>

export function EmailProviderCard({ integration }: { integration: EmailIntegration }) {
  const { t } = useI18n()
  const {
    query,
    provider,
    setProvider,
    enabled,
    setEnabled,
    fromEmail,
    setFromEmail,
    fromName,
    setFromName,
    dailyQuota,
    setDailyQuota,
    allowedDomains,
    setAllowedDomains,
    apiKey,
    setApiKey,
    mailgunDomain,
    setMailgunDomain,
    mailgunRegion,
    setMailgunRegion,
    testTo,
    setTestTo,
    activeMeta,
    save,
    test,
  } = integration

  return (
    <Card>
      <CardHeader className={clsx(styles.cardHeader)}>
        <div className={clsx(styles.cardIntro)}>
          <CardTitle>{t('integrations.email.cardTitle')}</CardTitle>
          <CardDescription>{t(activeMeta.descriptionKey)}</CardDescription>
        </div>
        <Badge variant={query.data?.enabled ? 'default' : 'secondary'}>
          {query.data?.enabled ? t('common.enabled') : t('common.disabled')}
        </Badge>
      </CardHeader>
      <CardContent className={clsx(styles.stackMd)}>
        {query.isLoading ? (
          <FormBlockSkeleton fields={5} />
        ) : query.isError ? (
          <p className={clsx(styles.error)}>
            {query.error instanceof Error ? query.error.message : t('common.requestFailed')}
          </p>
        ) : (
          <>
            <Form className={clsx(styles.stackMd)} onSubmit={() => save.mutate()}>
              <div className={clsx(styles.field)}>
                <Label>{t('integrations.email.provider')}</Label>
                <div className={clsx(styles.providerGrid)}>
                  {PROVIDERS.map((item) => (
                    <button
                      key={item.id}
                      type="button"
                      onClick={() => {
                        setProvider(item.id)
                        setApiKey('')
                      }}
                      className={clsx(
                        provider === item.id ? styles.providerBtnActive : styles.providerBtn,
                      )}
                    >
                      <div className={clsx(styles.providerTitle)}>{item.title}</div>
                      <div className={clsx(styles.providerDesc)}>{t(item.descriptionKey)}</div>
                    </button>
                  ))}
                </div>
              </div>

              <label className={clsx(styles.checkRow)}>
                <input
                  type="checkbox"
                  className={clsx(styles.checkInput)}
                  checked={enabled}
                  onChange={(e) => setEnabled(e.target.checked)}
                />
                <span>{t('integrations.email.enabled')}</span>
              </label>

              <div className={clsx(styles.grid2)}>
                <div className={clsx(styles.field)}>
                  <Label htmlFor="email-from">{t('integrations.email.fromEmail')}</Label>
                  <Input
                    id="email-from"
                    type="email"
                    value={fromEmail}
                    onChange={(e) => setFromEmail(e.target.value)}
                    placeholder="noreply@example.com"
                    autoComplete="off"
                  />
                </div>
                <div className={clsx(styles.field)}>
                  <Label htmlFor="email-from-name">{t('integrations.email.fromName')}</Label>
                  <Input
                    id="email-from-name"
                    value={fromName}
                    onChange={(e) => setFromName(e.target.value)}
                    placeholder="HCMS"
                    autoComplete="off"
                  />
                </div>
                <div className={clsx(styles.field)}>
                  <Label htmlFor="email-quota">{t('integrations.email.dailyQuota')}</Label>
                  <Input
                    id="email-quota"
                    type="number"
                    min={0}
                    value={dailyQuota}
                    onChange={(e) => setDailyQuota(Number(e.target.value) || 0)}
                  />
                </div>
                <div className={clsx(styles.field)}>
                  <Label htmlFor="email-domains">{t('integrations.email.allowedDomains')}</Label>
                  <Input
                    id="email-domains"
                    value={allowedDomains}
                    onChange={(e) => setAllowedDomains(e.target.value)}
                    placeholder="example.com, client.org"
                  />
                </div>
              </div>

              <div className={clsx(styles.field)}>
                <Label htmlFor="email-api-key">{t('integrations.email.apiKey')}</Label>
                <Input
                  id="email-api-key"
                  type="password"
                  value={apiKey}
                  onChange={(e) => setApiKey(e.target.value)}
                  placeholder={
                    query.data?.providers[provider]?.apiKeyConfigured
                      ? t('integrations.email.apiKeyKeep', {
                          masked: query.data.providers[provider].apiKeyMasked ?? '••••',
                        })
                      : apiKeyPlaceholder(provider)
                  }
                  autoComplete="new-password"
                />
                <p className={clsx(styles.hint)}>{t('integrations.email.apiKeyHint')}</p>
              </div>

              {provider === 'mailgun' ? (
                <div className={clsx(styles.grid2)}>
                  <div className={clsx(styles.field)}>
                    <Label htmlFor="mailgun-domain">{t('integrations.email.mailgunDomain')}</Label>
                    <Input
                      id="mailgun-domain"
                      value={mailgunDomain}
                      onChange={(e) => setMailgunDomain(e.target.value)}
                      placeholder="mg.example.com"
                      autoComplete="off"
                    />
                  </div>
                  <div className={clsx(styles.field)}>
                    <Label htmlFor="mailgun-region">{t('integrations.email.mailgunRegion')}</Label>
                    <Select
                      id="mailgun-region"
                      value={mailgunRegion}
                      onChange={(e) => setMailgunRegion(e.target.value === 'eu' ? 'eu' : 'us')}
                    >
                      <option value="us">{t('integrations.email.mailgunRegionUs')}</option>
                      <option value="eu">{t('integrations.email.mailgunRegionEu')}</option>
                    </Select>
                  </div>
                </div>
              ) : null}

              <div className={clsx(styles.actionsRow)}>
                <Button type="submit" disabled={save.isPending}>
                  {save.isPending ? t('common.saving') : t('common.save')}
                </Button>
              </div>
            </Form>

            <Form className={clsx(styles.testSection)} onSubmit={() => test.mutate()}>
              <div className={clsx(styles.field)}>
                <Label htmlFor="email-test-to">{t('integrations.email.testTo')}</Label>
                <Input
                  id="email-test-to"
                  type="email"
                  value={testTo}
                  onChange={(e) => setTestTo(e.target.value)}
                  placeholder="you@example.com"
                  autoComplete="email"
                />
              </div>
              <Button
                type="submit"
                variant="outline"
                disabled={test.isPending || testTo.trim() === ''}
              >
                {test.isPending
                  ? t('integrations.email.testing')
                  : t('integrations.email.sendTest')}
              </Button>
            </Form>
          </>
        )}
      </CardContent>
    </Card>
  )
}
