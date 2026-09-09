import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { clsx } from 'clsx'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { FormBlockSkeleton } from '@/components/skeletons'
import { api } from '@/lib/api'
import { copyToClipboard } from '@/lib/clipboard'
import { showError } from '@/lib/toast'
import { useI18n } from '@/i18n'
import styles from './OauthIntegrationsCard.module.css'

interface OauthConfig {
  google: {
    enabled: boolean
    clientId: string
    clientSecretConfigured: boolean
    clientSecretMasked: string | null
    redirectUri: string
  }
  telegram: {
    enabled: boolean
    botUsername: string
    botTokenConfigured: boolean
    botTokenMasked: string | null
  }
}

export function OauthIntegrationsCard() {
  const { t } = useI18n()
  const [googleEnabled, setGoogleEnabled] = useState(false)
  const [googleClientId, setGoogleClientId] = useState('')
  const [googleSecret, setGoogleSecret] = useState('')
  const [telegramEnabled, setTelegramEnabled] = useState(false)
  const [botUsername, setBotUsername] = useState('')
  const [botToken, setBotToken] = useState('')
  const [message, setMessage] = useState<string | null>(null)
  const [hydratedAt, setHydratedAt] = useState(0)

  const query = useQuery({
    queryKey: ['integrations-oauth'],
    queryFn: () => api<OauthConfig>('/admin/api/integrations/oauth'),
  })

  if (query.data && query.dataUpdatedAt !== hydratedAt) {
    setHydratedAt(query.dataUpdatedAt)
    setGoogleEnabled(query.data.google.enabled)
    setGoogleClientId(query.data.google.clientId)
    setGoogleSecret('')
    setTelegramEnabled(query.data.telegram.enabled)
    setBotUsername(query.data.telegram.botUsername)
    setBotToken('')
  }

  const save = useMutation({
    mutationFn: () =>
      api<OauthConfig>('/admin/api/integrations/oauth', {
        method: 'PUT',
        body: JSON.stringify({
          google: {
            enabled: googleEnabled,
            clientId: googleClientId,
            clientSecret: googleSecret,
          },
          telegram: {
            enabled: telegramEnabled,
            botUsername,
            botToken,
          },
        }),
      }),
    onSuccess: () => {
      setGoogleSecret('')
      setBotToken('')
      setMessage(t('integrations.oauth.saved'))
      void query.refetch()
    },
    onError: (err) => {
      setMessage(null)
      showError(err instanceof Error ? err.message : t('common.saveFailed'))
    },
  })

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('integrations.oauth.cardTitle')}</CardTitle>
        <CardDescription>{t('integrations.oauth.description')}</CardDescription>
      </CardHeader>
      <CardContent className={clsx(styles.content)}>
        {query.isLoading ? (
          <FormBlockSkeleton fields={4} />
        ) : query.isError ? (
          <p className={clsx(styles.error)}>
            {query.error instanceof Error ? query.error.message : t('common.requestFailed')}
          </p>
        ) : (
          <>
            <div className={clsx(styles.panel)}>
              <div className={clsx(styles.panelHeader)}>
                <div>
                  <h3 className={clsx(styles.panelTitle)}>Google</h3>
                  <p className={clsx(styles.hint)}>{t('integrations.oauth.googleHint')}</p>
                </div>
                <Badge variant={googleEnabled ? 'default' : 'secondary'}>
                  {googleEnabled ? t('common.enabled') : t('common.disabled')}
                </Badge>
              </div>
              <label className={clsx(styles.checkRow)}>
                <input
                  type="checkbox"
                  className={clsx(styles.checkInput)}
                  checked={googleEnabled}
                  onChange={(e) => setGoogleEnabled(e.target.checked)}
                />
                <span>{t('integrations.oauth.googleEnabled')}</span>
              </label>
              <div className={clsx(styles.grid)}>
                <div className={clsx(styles.field)}>
                  <Label htmlFor="google-client-id">{t('integrations.oauth.clientId')}</Label>
                  <Input
                    id="google-client-id"
                    value={googleClientId}
                    onChange={(e) => setGoogleClientId(e.target.value)}
                    autoComplete="off"
                  />
                  <p className={clsx(styles.hint)}>
                    {t('integrations.oauth.clientIdHelp')}{' '}
                    <a
                      className={clsx(styles.link)}
                      href="https://console.cloud.google.com/apis/credentials"
                      target="_blank"
                      rel="noreferrer"
                    >
                      console.cloud.google.com/apis/credentials
                    </a>
                  </p>
                </div>
                <div className={clsx(styles.field)}>
                  <Label htmlFor="google-client-secret">
                    {t('integrations.oauth.clientSecret')}
                  </Label>
                  <Input
                    id="google-client-secret"
                    type="password"
                    value={googleSecret}
                    onChange={(e) => setGoogleSecret(e.target.value)}
                    placeholder={
                      query.data?.google.clientSecretMasked
                        ? t('integrations.oauth.secretKeep', {
                            masked: query.data.google.clientSecretMasked,
                          })
                        : t('integrations.oauth.secretPlaceholder')
                    }
                    autoComplete="off"
                  />
                  <p className={clsx(styles.hint)}>{t('integrations.oauth.clientSecretHelp')}</p>
                </div>
              </div>
              {query.data?.google.redirectUri ? (
                <div className={clsx(styles.field)}>
                  <Label>{t('integrations.oauth.redirectUri')}</Label>
                  <div className={clsx(styles.row)}>
                    <Input readOnly value={query.data.google.redirectUri} />
                    <Button
                      type="button"
                      variant="outline"
                      onClick={() => void copyToClipboard(query.data.google.redirectUri)}
                    >
                      {t('common.copy')}
                    </Button>
                  </div>
                </div>
              ) : null}
            </div>

            <div className={clsx(styles.panel)}>
              <div className={clsx(styles.panelHeader)}>
                <div>
                  <h3 className={clsx(styles.panelTitle)}>Telegram</h3>
                  <p className={clsx(styles.hint)}>{t('integrations.oauth.telegramHint')}</p>
                </div>
                <Badge variant={telegramEnabled ? 'default' : 'secondary'}>
                  {telegramEnabled ? t('common.enabled') : t('common.disabled')}
                </Badge>
              </div>
              <label className={clsx(styles.checkRow)}>
                <input
                  type="checkbox"
                  className={clsx(styles.checkInput)}
                  checked={telegramEnabled}
                  onChange={(e) => setTelegramEnabled(e.target.checked)}
                />
                <span>{t('integrations.oauth.telegramEnabled')}</span>
              </label>
              <div className={clsx(styles.grid)}>
                <div className={clsx(styles.field)}>
                  <Label htmlFor="tg-bot">{t('integrations.oauth.botUsername')}</Label>
                  <Input
                    id="tg-bot"
                    value={botUsername}
                    onChange={(e) => setBotUsername(e.target.value)}
                    placeholder="MyCmsBot"
                    autoComplete="off"
                  />
                  <p className={clsx(styles.hint)}>
                    {t('integrations.oauth.botUsernameHelp')}{' '}
                    <a
                      className={clsx(styles.link)}
                      href="https://t.me/BotFather"
                      target="_blank"
                      rel="noreferrer"
                    >
                      t.me/BotFather
                    </a>
                  </p>
                </div>
                <div className={clsx(styles.field)}>
                  <Label htmlFor="tg-token">{t('integrations.oauth.botToken')}</Label>
                  <Input
                    id="tg-token"
                    type="password"
                    value={botToken}
                    onChange={(e) => setBotToken(e.target.value)}
                    placeholder={
                      query.data?.telegram.botTokenMasked
                        ? t('integrations.oauth.secretKeep', {
                            masked: query.data.telegram.botTokenMasked,
                          })
                        : t('integrations.oauth.secretPlaceholder')
                    }
                    autoComplete="off"
                  />
                  <p className={clsx(styles.hint)}>{t('integrations.oauth.botTokenHelp')}</p>
                </div>
              </div>
            </div>

            {message ? <p className={clsx(styles.muted)}>{message}</p> : null}
            <Button type="button" disabled={save.isPending} onClick={() => save.mutate()}>
              {save.isPending ? t('common.saving') : t('common.save')}
            </Button>
          </>
        )}
      </CardContent>
    </Card>
  )
}
