import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { FieldError } from '@/components/FieldError'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Form } from '@/components/ui/form'
import { Switch } from '@/components/ui/switch'
import { useAcl } from '@/hooks/useAcl'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import { apiFieldErrors, clearFieldError, type FieldErrors } from '@/lib/formErrors'
import { queryKeys } from '@/lib/queryKeys'
import { showSuccess } from '@/lib/toast'
import styles from './ApiAccessForm.module.css'

export interface GraphqlSettings {
  enabled: boolean
}

function GraphqlSettingsForm({ initial }: { initial: GraphqlSettings }) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({})
  const [draft, setDraft] = useState(initial)

  const save = useMutation({
    mutationFn: (payload: GraphqlSettings) =>
      api<{ graphql: GraphqlSettings }>('/admin/api/settings', {
        method: 'PATCH',
        body: JSON.stringify({ graphql: payload }),
      }),
    onSuccess: (data) => {
      setFieldErrors({})
      if (data.graphql) setDraft(data.graphql)
      void queryClient.invalidateQueries({ queryKey: queryKeys.settings.graphql })
      showSuccess(t('system.graphqlSaved'))
    },
    onError: (err) => setFieldErrors(apiFieldErrors(err)),
  })

  const isDirty = draft.enabled !== initial.enabled

  return (
    <Form
      className={styles.root}
      onSubmit={() => {
        save.mutate(draft)
      }}
    >
      <div className={styles.field}>
        <label className={styles.checkRow} htmlFor="graphql-enabled">
          <Switch
            id="graphql-enabled"
            checked={draft.enabled}
            onCheckedChange={(next) => {
              setDraft((prev) => ({ ...prev, enabled: next }))
              setFieldErrors((prev) => clearFieldError(prev, 'enabled'))
            }}
          />
          <span>{t('system.graphqlEnabled')}</span>
        </label>
        <p className={styles.hint}>{t('system.graphqlEnabledHint')}</p>
        <FieldError messages={fieldErrors.enabled} />
      </div>
      {isDirty ? (
        <Button type="submit" disabled={save.isPending}>
          {save.isPending ? t('common.saving') : t('system.graphqlSave')}
        </Button>
      ) : null}
    </Form>
  )
}

export function GraphqlSettingsCard() {
  const { t } = useI18n()
  const { isOwner } = useAcl()

  const query = useQuery({
    queryKey: queryKeys.settings.graphql,
    queryFn: () => api<GraphqlSettings>('/admin/api/settings/graphql'),
    enabled: isOwner,
  })

  if (!isOwner) return null
  if (!query.data) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>{t('system.graphqlTitle')}</CardTitle>
          <CardDescription>{t('common.loading')}</CardDescription>
        </CardHeader>
      </Card>
    )
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('system.graphqlTitle')}</CardTitle>
        <CardDescription>{t('system.graphqlHint')}</CardDescription>
      </CardHeader>
      <CardContent>
        <GraphqlSettingsForm key={JSON.stringify(query.data)} initial={query.data} />
      </CardContent>
    </Card>
  )
}
