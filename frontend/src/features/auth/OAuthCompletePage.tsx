import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useI18n, type MessageKey } from '@/i18n'
import { api, getToken, setToken } from '@/lib/api'
import { showError } from '@/lib/toast'
import styles from './OAuthCompletePage.module.css'
import type { AuthUser } from '@/types/system'

const OAUTH_ERRORS: Record<string, MessageKey> = {
  ACCOUNT_NOT_FOUND: 'login.oauth.accountNotFound',
  ACCOUNT_NOT_LINKED: 'login.oauth.accountNotLinked',
  ACCOUNT_DISABLED: 'login.oauth.accountDisabled',
  IDENTITY_TAKEN: 'account.identityTaken',
  INVALID_STATE: 'login.oauth.invalidState',
  PROVIDER_DISABLED: 'login.oauth.providerDisabled',
  PROVIDER_ERROR: 'login.oauth.providerError',
  TOO_MANY_REQUESTS: 'login.oauth.tooManyRequests',
  ALREADY_LINKED: 'account.alreadyLinked',
  UNAUTHORIZED: 'login.failed',
}

function readHash(): URLSearchParams {
  const raw = window.location.hash.startsWith('#')
    ? window.location.hash.slice(1)
    : window.location.hash
  return new URLSearchParams(raw)
}

export function OAuthCompletePage() {
  const { t } = useI18n()
  const navigate = useNavigate()
  const params = useMemo(() => readHash(), [])
  const token = params.get('token')
  const ticket = params.get('ticket')
  const linked = params.get('linked')
  const error = params.get('error')
  const [totpCode, setTotpCode] = useState('')
  const [pending, setPending] = useState(false)
  const [message, setMessage] = useState<string | null>(null)

  useEffect(() => {
    if (linked) {
      void navigate(getToken() ? '/settings/account' : '/login', { replace: true })
      return
    }
    if (token) {
      setToken(token)
      void navigate('/', { replace: true })
    }
  }, [linked, token, navigate])

  async function onTotp(event: FormEvent) {
    event.preventDefault()
    if (!ticket) return
    setPending(true)
    setMessage(null)
    try {
      const data = await api<{ token: string; user: AuthUser }>('/admin/api/auth/totp/complete', {
        method: 'POST',
        body: JSON.stringify({ ticket, totpCode }),
      })
      setToken(data.token)
      void navigate('/', { replace: true })
    } catch (err) {
      const text = err instanceof Error ? err.message : t('login.failed')
      setMessage(text)
      showError(text)
    } finally {
      setPending(false)
    }
  }

  const errorText = error ? t(OAUTH_ERRORS[error] ?? 'login.oauth.providerError') : null

  return (
    <div className={styles.center}>
      <Card className={styles.card}>
        <CardHeader>
          <CardTitle>{t('login.oauth.completeTitle')}</CardTitle>
          <CardDescription>
            {ticket ? t('login.totpRequired') : t('login.oauth.completeHint')}
          </CardDescription>
        </CardHeader>
        <CardContent className={styles.content}>
          {errorText ? <p className={styles.error}>{errorText}</p> : null}
          {ticket ? (
            <form
              onSubmit={(e) => {
                void onTotp(e)
              }}
              className={styles.form}
            >
              <div className={styles.field}>
                <Label htmlFor="oauth-totp">{t('login.totp')}</Label>
                <Input
                  id="oauth-totp"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  value={totpCode}
                  onChange={(e) => setTotpCode(e.target.value)}
                  required
                />
              </div>
              {message ? <p className={styles.error}>{message}</p> : null}
              <Button type="submit" className={styles.fullWidth} disabled={pending}>
                {pending ? t('login.submitting') : t('login.submit')}
              </Button>
            </form>
          ) : errorText ? (
            <Button
              className={styles.fullWidth}
              onClick={() => {
                void navigate('/login', { replace: true })
              }}
            >
              {t('login.title')}
            </Button>
          ) : null}
        </CardContent>
      </Card>
    </div>
  )
}
