import { useEffect, useRef, useState } from 'react'
import { useI18n } from '@/i18n'
import { isCopiedToastMessage } from '@/lib/clipboard'
import { onToast, type ToastPayload } from '@/lib/toast'
import { cn } from '@/lib/utils'

const DURATION_MS = 3500

export function AppToast() {
  const { t } = useI18n()
  const [toast, setToast] = useState<ToastPayload | null>(null)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => {
    return onToast((next) => {
      setToast(next)
      if (timer.current) clearTimeout(timer.current)
      timer.current = setTimeout(() => setToast(null), DURATION_MS)
    })
  }, [])

  useEffect(() => {
    return () => {
      if (timer.current) clearTimeout(timer.current)
    }
  }, [])

  if (!toast) return null

  const message = isCopiedToastMessage(toast.message) ? t('common.copied') : toast.message

  return (
    <div
      role={toast.kind === 'error' ? 'alert' : 'status'}
      className={cn(
        'fixed right-4 bottom-4 z-[100] max-w-sm rounded-md px-4 py-2 text-sm text-white shadow-lg',
        toast.kind === 'error' ? 'bg-destructive' : 'bg-success',
      )}
    >
      {message}
    </div>
  )
}
