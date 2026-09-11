import { useMutation } from '@tanstack/react-query'
import { clsx } from 'clsx'
import { useState } from 'react'
import { FieldError } from '@/components/FieldError'
import { PasswordField } from '@/components/PasswordField'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import { apiFieldErrors, type FieldErrors } from '@/lib/formErrors'
import { meetsPasswordPolicy } from '@/lib/password'
import { showSuccess } from '@/lib/toast'
import styles from './ChangePasswordDialog.module.css'

interface ChangePasswordResult {
  ok: boolean
  revokedSessions: number
}

export function ChangePasswordDialog() {
  const { t } = useI18n()
  const [open, setOpen] = useState(false)

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button variant="outline">{t('account.changePassword')}</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('account.changePassword')}</DialogTitle>
          <DialogDescription>{t('account.passwordHint')}</DialogDescription>
        </DialogHeader>
        {/* Mounted with the dialog, so every open starts from empty fields. */}
        <ChangePasswordForm onDone={() => setOpen(false)} />
      </DialogContent>
    </Dialog>
  )
}

function ChangePasswordForm({ onDone }: { onDone: () => void }) {
  const { t } = useI18n()
  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [confirm, setConfirm] = useState('')
  const [reveal, setReveal] = useState(false)
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({})

  const change = useMutation({
    mutationFn: () =>
      api<ChangePasswordResult>('/admin/api/auth/password', {
        method: 'POST',
        body: JSON.stringify({ currentPassword: current, newPassword: next }),
      }),
    onSuccess: (data) => {
      showSuccess(
        data.revokedSessions > 0
          ? `${t('account.passwordChanged')} ${t('account.sessionsRevoked', { count: data.revokedSessions })}`
          : t('account.passwordChanged'),
      )
      onDone()
    },
    onError: (err) => setFieldErrors(apiFieldErrors(err)),
  })

  const mismatch = confirm !== '' && next !== confirm
  const canSubmit =
    current !== '' && meetsPasswordPolicy(next) && next === confirm && !change.isPending

  return (
    <form
      className={clsx(styles.form)}
      onSubmit={(event) => {
        event.preventDefault()
        change.mutate()
      }}
    >
      <PasswordField
        id="account-current-password"
        label={t('account.currentPassword')}
        autoComplete="current-password"
        // eslint-disable-next-line jsx-a11y/no-autofocus -- focus current password when dialog opens
        autoFocus
        value={current}
        onChange={(value) => {
          setCurrent(value)
          setFieldErrors({})
        }}
        required
      />
      <FieldError messages={fieldErrors.currentPassword} />

      <PasswordField
        id="account-new-password"
        label={t('account.newPassword')}
        value={next}
        onChange={(value) => {
          setNext(value)
          setFieldErrors({})
        }}
        allowGenerate
        showCopy
        reveal={reveal}
        onRevealChange={setReveal}
        onGenerate={(password) => {
          setConfirm(password)
          setFieldErrors({})
        }}
        hint={t('account.passwordPolicy')}
        required
      />
      <FieldError messages={fieldErrors.newPassword} />

      <div className={clsx(styles.fieldGroup)}>
        <PasswordField
          id="account-confirm-password"
          label={t('account.confirmPassword')}
          value={confirm}
          onChange={setConfirm}
          reveal={reveal}
          onRevealChange={setReveal}
          required
        />
        {mismatch ? <p className={clsx(styles.error)}>{t('account.passwordMismatch')}</p> : null}
      </div>

      <div className={clsx(styles.actions)}>
        <DialogClose asChild>
          <Button type="button" variant="ghost">
            {t('common.cancel')}
          </Button>
        </DialogClose>
        <Button type="submit" disabled={!canSubmit}>
          {change.isPending ? t('common.saving') : t('common.save')}
        </Button>
      </div>
    </form>
  )
}
