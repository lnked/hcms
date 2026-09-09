import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { QRCodeSVG } from 'qrcode.react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { api, getToken } from '@/lib/api'
import { useI18n } from '@/i18n'
import type { AuthUser } from '@/types/system'

function TotpSetup({ onDone }: { onDone: () => void }) {
  const { t } = useI18n()
  const [secret, setSecret] = useState<string | null>(null)
  const [otpauthUrl, setOtpauthUrl] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [message, setMessage] = useState<string | null>(null)

  const setup = useMutation({
    mutationFn: () =>
      api<{ secret: string; otpauthUrl: string }>('/admin/api/auth/totp/setup', { method: 'POST' }),
    onSuccess: (data) => {
      setSecret(data.secret)
      setOtpauthUrl(data.otpauthUrl)
    },
    onError: (err) => setMessage(err instanceof Error ? err.message : t('common.saveFailed')),
  })

  const enable = useMutation({
    mutationFn: () =>
      api('/admin/api/auth/totp/enable', {
        method: 'POST',
        body: JSON.stringify({ totpCode: code }),
      }),
    onSuccess: () => {
      setMessage(t('users.totpEnabledOk'))
      onDone()
    },
    onError: (err) => setMessage(err instanceof Error ? err.message : t('common.saveFailed')),
  })

  return (
    <div className="space-y-3">
      {!secret ? (
        <Button variant="outline" disabled={setup.isPending} onClick={() => setup.mutate()}>
          {t('users.totpSetup')}
        </Button>
      ) : (
        <>
          <p className="text-sm text-muted-foreground">{t('users.totpScan')}</p>
          {otpauthUrl ? (
            <div className="inline-flex rounded-md border bg-white p-3">
              <QRCodeSVG value={otpauthUrl} size={180} level="M" marginSize={0} />
            </div>
          ) : null}
          <div className="space-y-1">
            <p className="text-xs text-muted-foreground">{t('users.totpManual')}</p>
            <p className="break-all font-mono text-xs">{secret}</p>
          </div>
          <div className="flex max-w-xs gap-2">
            <Input
              autoFocus
              value={code}
              onChange={(e) => setCode(e.target.value)}
              placeholder={t('login.totp')}
              inputMode="numeric"
              autoComplete="one-time-code"
            />
            <Button disabled={enable.isPending || code.length < 6} onClick={() => enable.mutate()}>
              {t('users.totpConfirm')}
            </Button>
          </div>
        </>
      )}
      {message ? <p className="text-sm text-muted-foreground">{message}</p> : null}
    </div>
  )
}

function TotpDisable({ onDone }: { onDone: () => void }) {
  const { t } = useI18n()
  const [password, setPassword] = useState('')
  const [message, setMessage] = useState<string | null>(null)
  const disable = useMutation({
    mutationFn: () =>
      api('/admin/api/auth/totp/disable', {
        method: 'POST',
        body: JSON.stringify({ password }),
      }),
    onSuccess: () => {
      setMessage(t('users.totpDisabledOk'))
      onDone()
    },
    onError: (err) => setMessage(err instanceof Error ? err.message : t('common.saveFailed')),
  })

  return (
    <div className="flex max-w-md flex-wrap items-end gap-2">
      <div className="space-y-2">
        <Label htmlFor="totp-disable-password">{t('common.password')}</Label>
        <Input
          id="totp-disable-password"
          type="password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
        />
      </div>
      <Button
        variant="outline"
        disabled={disable.isPending || password.length < 8}
        onClick={() => disable.mutate()}
      >
        {t('users.totpDisable')}
      </Button>
      {message ? <p className="w-full text-sm text-muted-foreground">{message}</p> : null}
    </div>
  )
}

export function TotpSection() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const me = useQuery({
    queryKey: ['auth-me', getToken()],
    queryFn: () => api<AuthUser>('/admin/api/auth/me'),
  })
  const refreshMe = () => void queryClient.invalidateQueries({ queryKey: ['auth-me'] })

  return (
    <div className="space-y-3">
      <div className="space-y-1">
        <p className="text-sm font-medium">{t('users.totpTitle')}</p>
        <p className="text-sm text-muted-foreground">
          {me.data?.totpEnabled ? t('users.totpEnabled') : t('users.totpDisabled')}
        </p>
      </div>
      {!me.data?.totpEnabled ? (
        <TotpSetup onDone={refreshMe} />
      ) : (
        <TotpDisable onDone={refreshMe} />
      )}
    </div>
  )
}
