import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Copy, Eye, EyeOff, Wand2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { api, ApiError } from '@/lib/api'
import { copyToClipboard } from '@/lib/clipboard'
import { generatePassword, meetsPasswordPolicy } from '@/lib/password'
import { showSuccess } from '@/lib/toast'
import { useI18n } from '@/i18n'

interface ChangePasswordResult {
  ok: boolean
  revokedSessions: number
}

export function PasswordCard() {
  const { t } = useI18n()
  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [confirm, setConfirm] = useState('')
  const [reveal, setReveal] = useState(false)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})

  const change = useMutation({
    mutationFn: () =>
      api<ChangePasswordResult>('/admin/api/auth/password', {
        method: 'POST',
        body: JSON.stringify({ currentPassword: current, newPassword: next }),
      }),
    onSuccess: (data) => {
      setCurrent('')
      setNext('')
      setConfirm('')
      setReveal(false)
      setFieldErrors({})
      showSuccess(
        data.revokedSessions > 0
          ? `${t('account.passwordChanged')} ${t('account.sessionsRevoked', { count: data.revokedSessions })}`
          : t('account.passwordChanged'),
      )
    },
    onError: (err) => {
      setFieldErrors(err instanceof ApiError && err.status === 422 ? err.fields : {})
    },
  })

  const mismatch = confirm !== '' && next !== confirm
  const canSubmit =
    current !== '' && meetsPasswordPolicy(next) && next === confirm && !change.isPending

  function suggest(): void {
    const generated = generatePassword()
    setNext(generated)
    setConfirm(generated)
    setReveal(true)
    setFieldErrors({})
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('account.passwordTitle')}</CardTitle>
        <CardDescription>{t('account.passwordHint')}</CardDescription>
      </CardHeader>
      <CardContent>
        <form
          className="max-w-md space-y-4"
          onSubmit={(event) => {
            event.preventDefault()
            change.mutate()
          }}
        >
          <div className="space-y-2">
            <Label htmlFor="account-current-password">{t('account.currentPassword')}</Label>
            <Input
              id="account-current-password"
              type="password"
              autoComplete="current-password"
              value={current}
              onChange={(e) => {
                setCurrent(e.target.value)
                setFieldErrors({})
              }}
              required
            />
            <FieldError messages={fieldErrors.currentPassword} />
          </div>

          <div className="space-y-2">
            <div className="flex items-center justify-between gap-2">
              <Label htmlFor="account-new-password">{t('account.newPassword')}</Label>
              <div className="flex items-center gap-1">
                <Button type="button" variant="ghost" size="sm" onClick={suggest}>
                  <Wand2 className="mr-1 size-3.5" />
                  {t('account.generate')}
                </Button>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  disabled={next === ''}
                  title={t('account.copy')}
                  aria-label={t('account.copy')}
                  onClick={() => void copyToClipboard(next)}
                >
                  <Copy className="size-4" />
                </Button>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  title={reveal ? t('account.hidePassword') : t('account.showPassword')}
                  aria-label={reveal ? t('account.hidePassword') : t('account.showPassword')}
                  onClick={() => setReveal((value) => !value)}
                >
                  {reveal ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                </Button>
              </div>
            </div>
            <Input
              id="account-new-password"
              type={reveal ? 'text' : 'password'}
              autoComplete="new-password"
              value={next}
              onChange={(e) => {
                setNext(e.target.value)
                setFieldErrors({})
              }}
              required
            />
            <p className="text-xs text-muted-foreground">{t('account.passwordPolicy')}</p>
            <FieldError messages={fieldErrors.newPassword} />
          </div>

          <div className="space-y-2">
            <Label htmlFor="account-confirm-password">{t('account.confirmPassword')}</Label>
            <Input
              id="account-confirm-password"
              type={reveal ? 'text' : 'password'}
              autoComplete="new-password"
              value={confirm}
              onChange={(e) => setConfirm(e.target.value)}
              required
            />
            {mismatch ? (
              <p className="text-sm text-destructive">{t('account.passwordMismatch')}</p>
            ) : null}
          </div>

          <Button type="submit" disabled={!canSubmit}>
            {change.isPending ? t('common.saving') : t('account.changePassword')}
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}

function FieldError({ messages }: { messages?: string[] }) {
  if (!messages || messages.length === 0) {
    return null
  }

  return <p className="text-sm text-destructive">{messages.join(' ')}</p>
}
