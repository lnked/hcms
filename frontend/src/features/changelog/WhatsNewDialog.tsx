import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import type { Release, SystemVersion } from '@/types/system'
import { useNavigate } from 'react-router-dom'

export function WhatsNewDialog({ version }: { version: SystemVersion }) {
  const { t } = useI18n()
  const navigate = useNavigate()
  const shouldShow = useMemo(() => {
    if (!version.changelogSeenVersion) {
      return true
    }
    return compareSemver(version.current, version.changelogSeenVersion) > 0
  }, [version])
  const [open, setOpen] = useState(shouldShow)

  const query = useQuery({
    queryKey: ['whats-new', version.changelogSeenVersion],
    queryFn: () => {
      const since = version.changelogSeenVersion
        ? `?since=${encodeURIComponent(version.changelogSeenVersion)}`
        : ''
      return api<Release[]>(`/admin/api/system/changelog${since}`)
    },
    enabled: open,
  })

  async function dismiss() {
    await api('/admin/api/system/changelog/seen', {
      method: 'POST',
      body: JSON.stringify({ version: version.current }),
    })
    setOpen(false)
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('whatsNew.title', { version: version.current })}</DialogTitle>
          <DialogDescription>{t('whatsNew.description')}</DialogDescription>
        </DialogHeader>

        <ul className="max-h-64 space-y-2 overflow-auto text-sm mb-2">
          {(query.data ?? []).flatMap((release) =>
            release.changes.map((change) => (
              <li key={`${release.version}-${change.text}`}>
                <strong>{release.version}</strong> · {change.text}
              </li>
            )),
          )}
        </ul>

        <div className="flex justify-end gap-2">
          <Button
            variant="outline"
            onClick={() => {
              void dismiss()
              navigate('/changelog')
            }}
          >
            {t('whatsNew.open')}
          </Button>
          <Button onClick={() => void dismiss()}>{t('common.close')}</Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

function compareSemver(a: string, b: string): number {
  const pa = a
    .replace(/^v/, '')
    .split('.')
    .map((n) => Number(n))
  const pb = b
    .replace(/^v/, '')
    .split('.')
    .map((n) => Number(n))
  for (let i = 0; i < 3; i += 1) {
    const da = pa[i] ?? 0
    const db = pb[i] ?? 0
    if (da !== db) {
      return da > db ? 1 : -1
    }
  }
  return 0
}
