import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { api } from '@/lib/api'
import { useI18n } from '@/i18n'

export interface ApiAccessSettings {
  unrestricted: boolean
  allowedOrigins: string[]
}

export function ApiAccessForm({
  initial,
  onMessage,
}: {
  initial: ApiAccessSettings
  onMessage: (message: string) => void
}) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [unrestricted, setUnrestricted] = useState(initial.unrestricted)
  const [originsText, setOriginsText] = useState(initial.allowedOrigins.join('\n'))

  const saveApiAccess = useMutation({
    mutationFn: () => {
      const allowedOrigins = originsText
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean)
      return api<{ apiAccess: ApiAccessSettings }>('/admin/api/settings', {
        method: 'PATCH',
        body: JSON.stringify({
          apiAccess: { unrestricted, allowedOrigins },
        }),
      })
    },
    onSuccess: (data) => {
      if (data.apiAccess) {
        setUnrestricted(data.apiAccess.unrestricted)
        setOriginsText(data.apiAccess.allowedOrigins.join('\n'))
      }
      void queryClient.invalidateQueries({ queryKey: ['settings-api-access'] })
      onMessage(t('system.apiAccessSaved'))
    },
    onError: (err) => onMessage(err instanceof Error ? err.message : t('common.saveFailed')),
  })

  return (
    <>
      <label className="flex items-start gap-2 text-sm">
        <input
          type="checkbox"
          className="mt-1"
          checked={unrestricted}
          onChange={(e) => setUnrestricted(e.target.checked)}
        />
        <span>{t('system.apiAccessUnrestricted')}</span>
      </label>
      <div className="space-y-2">
        <Label htmlFor="api-origins">{t('system.apiAccessOrigins')}</Label>
        <textarea
          id="api-origins"
          rows={5}
          disabled={unrestricted}
          value={originsText}
          onChange={(e) => setOriginsText(e.target.value)}
          className="flex min-h-[120px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
        />
        <p className="text-xs text-muted-foreground">{t('system.apiAccessOriginsHint')}</p>
      </div>
      <Button disabled={saveApiAccess.isPending} onClick={() => saveApiAccess.mutate()}>
        {saveApiAccess.isPending ? t('common.saving') : t('system.apiAccessSave')}
      </Button>
    </>
  )
}
