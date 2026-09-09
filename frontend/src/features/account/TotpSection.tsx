import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Copy } from 'lucide-react'
import { QRCodeSVG } from 'qrcode.react'
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
import { api, getToken } from '@/lib/api'
import { copyToClipboard } from '@/lib/clipboard'
import { showSuccess } from '@/lib/toast'
import { useI18n } from '@/i18n'
import type { AuthUser } from '@/types/system'

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
    return <p className="text-sm text-muted-foreground">{t('common.loading')}</p>
  }

  if (!secret || !otpauthUrl) {
    return (
      <div className="space-y-4">
        {setupError ? <p className="text-sm text-destructive">{setupError}</p> : null}
        <div className="flex justify-end gap-2">
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
      className="space-y-4"
      onSubmit={(event) => {
        event.preventDefault()
        setError(null)
        enable.mutate()
      }}
    >
      <div className="space-y-2">
        <p className="text-sm text-muted-foreground">{t('users.totpScan')}</p>
        <div className="inline-flex rounded-md border bg-white p-3">
          <QRCodeSVG value={otpauthUrl} size={180} level="M" marginSize={0} />
        </div>
      </div>

      <div className="space-y-2">
        <div className="flex items-center justify-between gap-2">
          <Label>{t('users.totpManual')}</Label>
          <Button
            type="button"
            variant="ghost"
            size="icon"
            title={t('account.copy')}
            aria-label={t('account.copy')}
            onClick={() => void copyToClipboard(secret)}
          >
            <Copy className="size-4" />
          </Button>
        </div>
        <p className="break-all rounded-md border bg-muted/40 px-3 py-2 font-mono text-xs">
          {secret}
        </p>
      </div>

      <div className="space-y-2">
        <Label htmlFor="totp-enable-code">{t('login.totp')}</Label>
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

      {error ? <p className="text-sm text-destructive">{error}</p> : null}

      <div className="flex justify-end gap-2">
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
      className="space-y-4"
      onSubmit={(event) => {
        event.preventDefault()
        setError(null)
        disable.mutate()
      }}
    >
      <div className="space-y-2">
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

      {error ? <p className="text-sm text-destructive">{error}</p> : null}

      <div className="flex justify-end gap-2">
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
