import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { FieldError } from '@/components/FieldError'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Form } from '@/components/ui/form'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useAcl } from '@/hooks/useAcl'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import { apiFieldErrors, clearFieldError, hasFieldError, type FieldErrors } from '@/lib/formErrors'
import { queryKeys } from '@/lib/queryKeys'
import { showSuccess } from '@/lib/toast'
import styles from './ApiAccessForm.module.css'

export interface SecuritySettings {
  ipAutoBlockAfterSpamRejects: number
  ipAutoBlockAfterLoginBlocks: number
  ipAutoBlockWindowSeconds: number
  ipAutoBlockTtlSeconds: number
}

function SecuritySettingsForm({ initial }: { initial: SecuritySettings }) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({})
  const [draft, setDraft] = useState(initial)

  const save = useMutation({
    mutationFn: (payload: SecuritySettings) =>
      api<{ security: SecuritySettings }>('/admin/api/settings', {
        method: 'PATCH',
        body: JSON.stringify({ security: payload }),
      }),
    onSuccess: (data) => {
      setFieldErrors({})
      if (data.security) setDraft(data.security)
      void queryClient.invalidateQueries({ queryKey: queryKeys.settings.security })
      showSuccess(t('system.securitySaved'))
    },
    onError: (err) => setFieldErrors(apiFieldErrors(err)),
  })

  const isDirty =
    draft.ipAutoBlockAfterSpamRejects !== initial.ipAutoBlockAfterSpamRejects ||
    draft.ipAutoBlockAfterLoginBlocks !== initial.ipAutoBlockAfterLoginBlocks ||
    draft.ipAutoBlockWindowSeconds !== initial.ipAutoBlockWindowSeconds ||
    draft.ipAutoBlockTtlSeconds !== initial.ipAutoBlockTtlSeconds

  function setNumber(field: keyof SecuritySettings, raw: string) {
    const value = raw === '' ? 0 : Number(raw)
    setDraft((prev) => ({ ...prev, [field]: Number.isFinite(value) ? value : 0 }))
    setFieldErrors((prev) => clearFieldError(prev, field))
  }

  return (
    <Form
      className={styles.root}
      onSubmit={() => {
        save.mutate(draft)
      }}
    >
      <div className={styles.field}>
        <Label htmlFor="sec-spam">{t('system.securitySpamThreshold')}</Label>
        <Input
          id="sec-spam"
          type="number"
          min={0}
          value={draft.ipAutoBlockAfterSpamRejects}
          aria-invalid={hasFieldError(fieldErrors, 'ipAutoBlockAfterSpamRejects') || undefined}
          onChange={(e) => setNumber('ipAutoBlockAfterSpamRejects', e.target.value)}
        />
        <p className={styles.hint}>{t('system.securitySpamThresholdHint')}</p>
        <FieldError messages={fieldErrors.ipAutoBlockAfterSpamRejects} />
      </div>
      <div className={styles.field}>
        <Label htmlFor="sec-login">{t('system.securityLoginThreshold')}</Label>
        <Input
          id="sec-login"
          type="number"
          min={0}
          value={draft.ipAutoBlockAfterLoginBlocks}
          aria-invalid={hasFieldError(fieldErrors, 'ipAutoBlockAfterLoginBlocks') || undefined}
          onChange={(e) => setNumber('ipAutoBlockAfterLoginBlocks', e.target.value)}
        />
        <FieldError messages={fieldErrors.ipAutoBlockAfterLoginBlocks} />
      </div>
      <div className={styles.field}>
        <Label htmlFor="sec-window">{t('system.securityWindow')}</Label>
        <Input
          id="sec-window"
          type="number"
          min={60}
          value={draft.ipAutoBlockWindowSeconds}
          aria-invalid={hasFieldError(fieldErrors, 'ipAutoBlockWindowSeconds') || undefined}
          onChange={(e) => setNumber('ipAutoBlockWindowSeconds', e.target.value)}
        />
        <FieldError messages={fieldErrors.ipAutoBlockWindowSeconds} />
      </div>
      <div className={styles.field}>
        <Label htmlFor="sec-ttl">{t('system.securityTtl')}</Label>
        <Input
          id="sec-ttl"
          type="number"
          min={60}
          value={draft.ipAutoBlockTtlSeconds}
          aria-invalid={hasFieldError(fieldErrors, 'ipAutoBlockTtlSeconds') || undefined}
          onChange={(e) => setNumber('ipAutoBlockTtlSeconds', e.target.value)}
        />
        <FieldError messages={fieldErrors.ipAutoBlockTtlSeconds} />
      </div>
      {isDirty ? (
        <Button type="submit" disabled={save.isPending}>
          {save.isPending ? t('common.saving') : t('system.securitySave')}
        </Button>
      ) : null}
    </Form>
  )
}

export function SecuritySettingsCard() {
  const { t } = useI18n()
  const { isOwner } = useAcl()

  const query = useQuery({
    queryKey: queryKeys.settings.security,
    queryFn: () => api<SecuritySettings>('/admin/api/settings/security'),
    enabled: isOwner,
  })

  if (!isOwner) return null
  if (!query.data) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>{t('system.securityTitle')}</CardTitle>
          <CardDescription>{t('common.loading')}</CardDescription>
        </CardHeader>
      </Card>
    )
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('system.securityTitle')}</CardTitle>
        <CardDescription>{t('system.securityHint')}</CardDescription>
      </CardHeader>
      <CardContent>
        <SecuritySettingsForm
          key={JSON.stringify(query.data)}
          initial={query.data}
        />
      </CardContent>
    </Card>
  )
}
