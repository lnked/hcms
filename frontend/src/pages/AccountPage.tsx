import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { FormBlockSkeleton } from '@/components/skeletons'
import { api, ApiError } from '@/lib/api'
import { showError, showSuccess } from '@/lib/toast'
import { useI18n } from '@/i18n'
import { PasswordCard } from '@/features/account/PasswordCard'
import { TotpCard } from '@/features/account/TotpCard'
import { TelegramLoginButton, type TelegramAuthPayload } from '@/features/auth/TelegramLoginButton'

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
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">{t('account.title')}</h1>
        <p className="mt-1 text-sm text-muted-foreground">{t('account.description')}</p>
      </div>

      {identities.isLoading ? (
        <FormBlockSkeleton fields={4} />
      ) : identities.isError ? (
        <p className="text-sm text-destructive">
          {identities.error instanceof Error ? identities.error.message : t('common.loadError')}
        </p>
      ) : (
        <div className="grid gap-6 lg:grid-cols-2">
          <Card>
            <CardHeader className="flex flex-row items-start justify-between gap-4 space-y-0">
              <div className="space-y-1.5">
                <CardTitle>Google</CardTitle>
                <CardDescription>
                  {googleReady ? t('account.googleHint') : t('account.providerOff')}
                </CardDescription>
              </div>
              <Badge variant={google?.linked ? 'default' : 'secondary'}>
                {google?.linked ? t('account.linkedStatus') : t('account.notLinked')}
              </Badge>
            </CardHeader>
            <CardContent className="space-y-3">
              {google?.linked && google.label ? (
                <p className="text-sm text-muted-foreground">{google.label}</p>
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
            <CardHeader className="flex flex-row items-start justify-between gap-4 space-y-0">
              <div className="space-y-1.5">
                <CardTitle>Telegram</CardTitle>
                <CardDescription>
                  {telegramReady ? t('account.telegramHint') : t('account.providerOff')}
                </CardDescription>
              </div>
              <Badge variant={telegram?.linked ? 'default' : 'secondary'}>
                {telegram?.linked ? t('account.linkedStatus') : t('account.notLinked')}
              </Badge>
            </CardHeader>
            <CardContent className="space-y-3">
              {telegram?.linked && telegram.label ? (
                <p className="text-sm text-muted-foreground">{telegram.label}</p>
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

      <PasswordCard />
      <TotpCard />
    </div>
  )
}
