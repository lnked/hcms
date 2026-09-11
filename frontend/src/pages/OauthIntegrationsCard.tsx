import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { clsx } from 'clsx'
import { useState } from 'react'
import { FormBlockSkeleton } from '@/components/skeletons'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import { copyToClipboard } from '@/lib/clipboard'
import { showSuccess } from '@/lib/toast'
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
  const queryClient = useQueryClient()
  const [googleEnabled, setGoogleEnabled] = useState(false)
  const [googleClientId, setGoogleClientId] = useState('')
  const [googleSecret, setGoogleSecret] = useState('')
  const [telegramEnabled, setTelegramEnabled] = useState(false)
  const [botUsername, setBotUsername] = useState('')
  const [botToken, setBotToken] = useState('')
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
      showSuccess(t('account.oauth.saved'))
      void query.refetch()
      void queryClient.invalidateQueries({ queryKey: ['auth-providers'] })
    },
  })

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('account.oauth.cardTitle')}</CardTitle>
        <CardDescription>{t('account.oauth.description')}</CardDescription>
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
                  <p className={clsx(styles.hint)}>{t('account.oauth.googleHint')}</p>
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
                <span>{t('account.oauth.googleEnabled')}</span>
              </label>
              <div className={clsx(styles.grid)}>
                <div className={clsx(styles.field)}>
                  <Label htmlFor="google-client-id">{t('account.oauth.clientId')}</Label>
                  <Input
                    id="google-client-id"
                    value={googleClientId}
                    onChange={(e) => setGoogleClientId(e.target.value)}
                    autoComplete="off"
                  />
                  <p className={clsx(styles.hint)}>
                    {t('account.oauth.clientIdHelp')}{' '}
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
                  <Label htmlFor="google-client-secret">{t('account.oauth.clientSecret')}</Label>
                  <Input
                    id="google-client-secret"
                    type="password"
                    value={googleSecret}
                    onChange={(e) => setGoogleSecret(e.target.value)}
                    placeholder={
                      query.data?.google.clientSecretMasked
                        ? t('account.oauth.secretKeep', {
                            masked: query.data.google.clientSecretMasked,
                          })
                        : t('account.oauth.secretPlaceholder')
                    }
                    autoComplete="off"
                  />
                  <p className={clsx(styles.hint)}>{t('account.oauth.clientSecretHelp')}</p>
                </div>
              </div>
              {query.data?.google.redirectUri ? (
                <div className={clsx(styles.field)}>
                  <Label>{t('account.oauth.redirectUri')}</Label>
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
                  <p className={clsx(styles.hint)}>{t('account.oauth.telegramHint')}</p>
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
                <span>{t('account.oauth.telegramEnabled')}</span>
              </label>
              <div className={clsx(styles.grid)}>
                <div className={clsx(styles.field)}>
                  <Label htmlFor="tg-bot">{t('account.oauth.botUsername')}</Label>
                  <Input
                    id="tg-bot"
                    value={botUsername}
                    onChange={(e) => setBotUsername(e.target.value)}
                    placeholder="MyCmsBot"
                    autoComplete="off"
                  />
                  <p className={clsx(styles.hint)}>
                    {t('account.oauth.botUsernameHelp')}{' '}
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
                  <Label htmlFor="tg-token">{t('account.oauth.botToken')}</Label>
                  <Input
                    id="tg-token"
                    type="password"
                    value={botToken}
                    onChange={(e) => setBotToken(e.target.value)}
                    placeholder={
                      query.data?.telegram.botTokenMasked
                        ? t('account.oauth.secretKeep', {
                            masked: query.data.telegram.botTokenMasked,
                          })
                        : t('account.oauth.secretPlaceholder')
                    }
                    autoComplete="off"
                  />
                  <p className={clsx(styles.hint)}>{t('account.oauth.botTokenHelp')}</p>
                </div>
              </div>
            </div>

            <Button
              type="button"
              className={clsx(styles.save)}
              disabled={save.isPending}
              onClick={() => save.mutate()}
            >
              {save.isPending ? t('common.saving') : t('common.save')}
            </Button>
          </>
        )}
      </CardContent>
    </Card>
  )
}
