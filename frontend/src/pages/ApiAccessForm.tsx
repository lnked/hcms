import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { clsx } from 'clsx'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { api } from '@/lib/api'
import { showSuccess } from '@/lib/toast'
import { useI18n } from '@/i18n'
import styles from './ApiAccessForm.module.css'

export interface ApiAccessSettings {
  unrestricted: boolean
  allowedOrigins: string[]
}

export function ApiAccessForm({
  initial,
}: {
  initial: ApiAccessSettings
}) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [unrestricted, setUnrestricted] = useState(initial.unrestricted)
  const [originsText, setOriginsText] = useState(initial.allowedOrigins.join('\n'))

  const parseOrigins = (text: string) =>
    text
      .split('\n')
      .map((line) => line.trim())
      .filter(Boolean)

  const allowedOrigins = parseOrigins(originsText)
  const originsChanged =
    allowedOrigins.length !== initial.allowedOrigins.length ||
    allowedOrigins.some((origin, i) => origin !== initial.allowedOrigins[i])
  const isDirty = unrestricted !== initial.unrestricted || (!unrestricted && originsChanged)

  const saveApiAccess = useMutation({
    mutationFn: () =>
      api<{ apiAccess: ApiAccessSettings }>('/admin/api/settings', {
        method: 'PATCH',
        body: JSON.stringify({
          apiAccess: { unrestricted, allowedOrigins },
        }),
      }),
    onSuccess: (data) => {
      if (data.apiAccess) {
        setUnrestricted(data.apiAccess.unrestricted)
        setOriginsText(data.apiAccess.allowedOrigins.join('\n'))
      }
      void queryClient.invalidateQueries({ queryKey: ['settings-api-access'] })
      showSuccess(t('system.apiAccessSaved'))
    },
  })

  return (
    <>
      <label className={clsx(styles.checkRow)}>
        <input
          type="checkbox"
          className={clsx(styles.checkInput)}
          checked={unrestricted}
          onChange={(e) => setUnrestricted(e.target.checked)}
        />
        <span>{t('system.apiAccessUnrestricted')}</span>
      </label>
      {!unrestricted ? (
        <div className={clsx(styles.field)}>
          <Label htmlFor="api-origins">{t('system.apiAccessOrigins')}</Label>
          <Textarea
            id="api-origins"
            rows={5}
            value={originsText}
            onChange={(e) => setOriginsText(e.target.value)}
          />
          <p className={clsx(styles.hint)}>{t('system.apiAccessOriginsHint')}</p>
        </div>
      ) : null}
      {isDirty ? (
        <Button disabled={saveApiAccess.isPending} onClick={() => saveApiAccess.mutate()}>
          {saveApiAccess.isPending ? t('common.saving') : t('system.apiAccessSave')}
        </Button>
      ) : null}
    </>
  )
}
