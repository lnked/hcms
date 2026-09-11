import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { clsx } from 'clsx'
import { FormBlockSkeleton } from '@/components/skeletons'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { SecurityCard } from '@/features/account/SecurityCard'
import { TelegramLoginButton, type TelegramAuthPayload } from '@/features/auth/TelegramLoginButton'
import { useAcl } from '@/hooks/useAcl'
import { useI18n } from '@/i18n'
import { api, ApiError } from '@/lib/api'
import { showError, showSuccess } from '@/lib/toast'
import styles from './AccountPage.module.css'
import { OauthIntegrationsCard } from './OauthIntegrationsCard'

interface AuthProviders {
  google: { enabled: boolean; clientId: string }
  telegram: { enabled: boolean; botUsername: string }
}

interface IdentityRow {
  provider: 'google' | 'telegram'
  linked: boolean
  label: string | null
}

export function AccountPage() {
  const { t } = useI18n()
  const { canSection } = useAcl()
  const canManageOauth = canSection('integrations', 'admin')
  const queryClient = useQueryClient()
  const providers = useQuery({
    queryKey: ['auth-providers'],
    queryFn: () => api<AuthProviders>('/admin/api/auth/providers'),
    staleTime: 30_000,
  })
  const identities = useQuery({
    queryKey: ['auth-identities'],
    queryFn: () => api<IdentityRow[]>('/admin/api/auth/identities'),
  })

  const unlink = useMutation({
    mutationFn: (provider: 'google' | 'telegram') =>
      api<IdentityRow[]>(`/admin/api/auth/identities/${provider}`, { method: 'DELETE' }),
    onSuccess: (rows) => {
      queryClient.setQueryData(['auth-identities'], rows)
      showSuccess(t('account.unlinked'))
    },
    onError: (err) => showError(err instanceof Error ? err.message : t('common.saveFailed')),
  })

  const linkTelegram = useMutation({
    mutationFn: (payload: TelegramAuthPayload) =>
      api<IdentityRow[]>('/admin/api/auth/identities/telegram', {
        method: 'POST',
        body: JSON.stringify(payload),
      }),
    onSuccess: (rows) => {
      queryClient.setQueryData(['auth-identities'], rows)
      showSuccess(t('account.linked'))
    },
    onError: (err) => {
      if (err instanceof ApiError && err.code === 'IDENTITY_TAKEN') {
        showError(t('account.identityTaken'))
        return
      }
      showError(err instanceof Error ? err.message : t('common.saveFailed'))
    },
  })

  const linkGoogle = useMutation({
    mutationFn: () =>
      api<{ url: string }>('/admin/api/auth/identities/google/start', { method: 'POST' }),
    onSuccess: (data) => {
      window.location.assign(data.url)
    },
    onError: (err) => {
      if (err instanceof ApiError && err.code === 'PROVIDER_DISABLED') {
        showError(t('account.providerDisabled'))
        return
      }
      showError(err instanceof Error ? err.message : t('common.saveFailed'))
    },
  })

  const google = identities.data?.find((row) => row.provider === 'google')
  const telegram = identities.data?.find((row) => row.provider === 'telegram')
  const googleReady = providers.data?.google.enabled === true
  const telegramReady = Boolean(
    providers.data?.telegram.enabled && providers.data.telegram.botUsername,
  )

  return (
    <div className={clsx(styles.root)}>
      <div>
        <h1 className={clsx(styles.title)}>{t('account.title')}</h1>
        <p className={clsx(styles.subtitle)}>{t('account.description')}</p>
      </div>

      {identities.isLoading ? (
        <FormBlockSkeleton fields={4} />
      ) : identities.isError ? (
        <p className={clsx(styles.error)}>
          {identities.error instanceof Error ? identities.error.message : t('common.loadError')}
        </p>
      ) : (
        <div className={clsx(styles.grid)}>
          <Card>
            <CardHeader className={clsx(styles.cardHeader)}>
              <div className={clsx(styles.cardIntro)}>
                <CardTitle>Google</CardTitle>
                <CardDescription>
                  {googleReady
                    ? t('account.googleHint')
                    : canManageOauth
                      ? t('account.providerOff')
                      : t('account.providerOffAskAdmin')}
                </CardDescription>
              </div>
              <Badge variant={google?.linked ? 'default' : 'secondary'}>
                {google?.linked ? t('account.linkedStatus') : t('account.notLinked')}
              </Badge>
            </CardHeader>
            <CardContent className={clsx(styles.cardBody)}>
              {google?.linked && google.label ? (
                <p className={clsx(styles.muted)}>{google.label}</p>
              ) : null}
              {google?.linked ? (
                <Button
                  variant="outline"
                  disabled={unlink.isPending}
                  onClick={() => unlink.mutate('google')}
                >
                  {t('account.disconnect')}
                </Button>
              ) : (
                <Button
                  disabled={!googleReady || linkGoogle.isPending}
                  onClick={() => linkGoogle.mutate()}
                >
                  {t('account.connectGoogle')}
                </Button>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader className={clsx(styles.cardHeader)}>
              <div className={clsx(styles.cardIntro)}>
                <CardTitle>Telegram</CardTitle>
                <CardDescription>
                  {telegramReady
                    ? t('account.telegramHint')
                    : canManageOauth
                      ? t('account.providerOff')
                      : t('account.providerOffAskAdmin')}
                </CardDescription>
              </div>
              <Badge variant={telegram?.linked ? 'default' : 'secondary'}>
                {telegram?.linked ? t('account.linkedStatus') : t('account.notLinked')}
              </Badge>
            </CardHeader>
            <CardContent className={clsx(styles.cardBody)}>
              {telegram?.linked && telegram.label ? (
                <p className={clsx(styles.muted)}>{telegram.label}</p>
              ) : null}
              {telegram?.linked ? (
                <Button
                  variant="outline"
                  disabled={unlink.isPending}
                  onClick={() => unlink.mutate('telegram')}
                >
                  {t('account.disconnect')}
                </Button>
              ) : telegramReady ? (
                <TelegramLoginButton
                  botUsername={providers.data?.telegram.botUsername ?? ''}
                  onAuth={(user) => linkTelegram.mutate(user)}
                />
              ) : (
                <Button disabled>{t('account.connectTelegram')}</Button>
              )}
            </CardContent>
          </Card>
        </div>
      )}

      <SecurityCard />

      {canManageOauth ? <OauthIntegrationsCard /> : null}
    </div>
  )
}
