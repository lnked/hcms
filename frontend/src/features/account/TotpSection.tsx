import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { QRCodeSVG } from 'qrcode.react'
import { clsx } from 'clsx'
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
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { CodeBlock } from '@/components/CodeBlock'
import { api } from '@/lib/api'
import { queryKeys } from '@/lib/queryKeys'
import { showSuccess } from '@/lib/toast'
import { useAuthMe } from '@/hooks/useAcl'
import { useI18n } from '@/i18n'
import styles from './TotpSection.module.css'

export function TotpSection() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const me = useAuthMe()
  const refreshMe = () => void queryClient.invalidateQueries({ queryKey: queryKeys.auth.root })

  return (
    <div className={clsx(styles.root)}>
      <div className={clsx(styles.intro)}>
        <p className={clsx(styles.title)}>{t('users.totpTitle')}</p>
        <p className={clsx(styles.muted)}>
          {me.data?.totpEnabled ? t('users.totpEnabled') : t('users.totpDisabled')}
        </p>
      </div>
      {!me.data?.totpEnabled ? (
        <TotpSetupDialog onDone={refreshMe} />
      ) : (
        <TotpDisableDialog onDone={refreshMe} />
      )}
    </div>
  )
}

function TotpSetupDialog({ onDone }: { onDone: () => void }) {
  const { t } = useI18n()
  const [open, setOpen] = useState(false)

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button variant="outline">{t('users.totpSetup')}</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('users.totpSetup')}</DialogTitle>
          <DialogDescription>{t('users.totpHint')}</DialogDescription>
        </DialogHeader>
        {/* Remount on each open so setup starts fresh. */}
        <TotpSetupForm
          onDone={() => {
            setOpen(false)
            onDone()
          }}
        />
      </DialogContent>
    </Dialog>
  )
}

function TotpSetupForm({ onDone }: { onDone: () => void }) {
  const { t } = useI18n()
  const [code, setCode] = useState('')
  const [error, setError] = useState<string | null>(null)

  const setup = useQuery({
    queryKey: ['totp-setup'],
    queryFn: () =>
      api<{ secret: string; otpauthUrl: string }>('/admin/api/auth/totp/setup', { method: 'POST' }),
    retry: false,
    staleTime: Infinity,
    gcTime: 0,
  })

  const enable = useMutation({
    mutationFn: () =>
      api('/admin/api/auth/totp/enable', {
        method: 'POST',
        body: JSON.stringify({ totpCode: code }),
      }),
    onSuccess: () => {
      showSuccess(t('users.totpEnabledOk'))
      onDone()
    },
    onError: (err) => setError(err instanceof Error ? err.message : t('common.saveFailed')),
  })

  const secret = setup.data?.secret
  const otpauthUrl = setup.data?.otpauthUrl
  const setupError =
    setup.error instanceof Error
      ? setup.error.message
      : setup.isError
        ? t('common.saveFailed')
        : null

  if (setup.isPending) {
    return <p className={clsx(styles.muted)}>{t('common.loading')}</p>
  }

  if (!secret || !otpauthUrl) {
    return (
      <div className={clsx(styles.stack)}>
        {setupError ? <p className={clsx(styles.error)}>{setupError}</p> : null}
        <div className={clsx(styles.actions)}>
          <DialogClose asChild>
            <Button type="button" variant="ghost">
              {t('common.cancel')}
            </Button>
          </DialogClose>
          <Button type="button" disabled={setup.isFetching} onClick={() => void setup.refetch()}>
            {t('common.retry')}
          </Button>
        </div>
      </div>
    )
  }

  return (
    <form
      className={clsx(styles.stack)}
      onSubmit={(event) => {
        event.preventDefault()
        setError(null)
        enable.mutate()
      }}
    >
      <div className={clsx(styles.stackSm)}>
        <p className={clsx(styles.muted)}>{t('users.totpScan')}</p>
        <div className={clsx(styles.qrWrap)}>
          <QRCodeSVG value={otpauthUrl} size={180} level="M" marginSize={0} />
        </div>
      </div>

      <CodeBlock code={secret} label={t('users.totpManual')} language="http" />

      <div>
        <Label htmlFor="totp-enable-code" className={clsx(styles.labelBlock)}>
          {t('login.totp')}
        </Label>

        <Input
          id="totp-enable-code"
          autoFocus
          value={code}
          onChange={(e) => {
            setCode(e.target.value)
            setError(null)
          }}
          placeholder={t('login.totp')}
          inputMode="numeric"
          autoComplete="one-time-code"
          required
        />
      </div>

      {error ? <p className={clsx(styles.error)}>{error}</p> : null}

      <div className={clsx(styles.actions)}>
        <DialogClose asChild>
          <Button type="button" variant="ghost">
            {t('common.cancel')}
          </Button>
        </DialogClose>
        <Button type="submit" disabled={enable.isPending || code.length < 6}>
          {enable.isPending ? t('common.saving') : t('users.totpConfirm')}
        </Button>
      </div>
    </form>
  )
}

function TotpDisableDialog({ onDone }: { onDone: () => void }) {
  const { t } = useI18n()
  const [open, setOpen] = useState(false)

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button variant="outline">{t('users.totpDisable')}</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('users.totpDisable')}</DialogTitle>
          <DialogDescription>{t('users.totpEnabled')}</DialogDescription>
        </DialogHeader>
        <TotpDisableForm
          onDone={() => {
            setOpen(false)
            onDone()
          }}
        />
      </DialogContent>
    </Dialog>
  )
}

function TotpDisableForm({ onDone }: { onDone: () => void }) {
  const { t } = useI18n()
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)

  const disable = useMutation({
    mutationFn: () =>
      api('/admin/api/auth/totp/disable', {
        method: 'POST',
        body: JSON.stringify({ password }),
      }),
    onSuccess: () => {
      showSuccess(t('users.totpDisabledOk'))
      onDone()
    },
    onError: (err) => setError(err instanceof Error ? err.message : t('common.saveFailed')),
  })

  return (
    <form
      className={clsx(styles.stack)}
      onSubmit={(event) => {
        event.preventDefault()
        setError(null)
        disable.mutate()
      }}
    >
      <div className={clsx(styles.stackSm)}>
        <Label htmlFor="totp-disable-password">{t('common.password')}</Label>
        <Input
          id="totp-disable-password"
          type="password"
          autoComplete="current-password"
          autoFocus
          value={password}
          onChange={(e) => {
            setPassword(e.target.value)
            setError(null)
          }}
          required
        />
      </div>

      {error ? <p className={clsx(styles.error)}>{error}</p> : null}

      <div className={clsx(styles.actions)}>
        <DialogClose asChild>
          <Button type="button" variant="ghost">
            {t('common.cancel')}
          </Button>
        </DialogClose>
        <Button type="submit" disabled={disable.isPending || password.length < 8}>
          {disable.isPending ? t('common.saving') : t('users.totpDisable')}
        </Button>
      </div>
    </form>
  )
}
