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
  playground: boolean
}

function normalizeGraphqlSettings(
  raw: Partial<GraphqlSettings> | null | undefined,
): GraphqlSettings {
  return {
    enabled: raw?.enabled === true,
    playground: raw?.playground === true,
  }
}

function GraphqlSettingsForm({
  initial,
  readOnly,
}: {
  initial: GraphqlSettings
  readOnly: boolean
}) {
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
      if (data.graphql) setDraft(normalizeGraphqlSettings(data.graphql))
      void queryClient.invalidateQueries({ queryKey: queryKeys.settings.graphql })
      showSuccess(t('system.graphqlSaved'))
    },
    onError: (err) => setFieldErrors(apiFieldErrors(err)),
  })

  const isDirty = draft.enabled !== initial.enabled || draft.playground !== initial.playground

  return (
    <Form
      className={styles.root}
      onSubmit={() => {
        if (readOnly) return
        save.mutate(draft)
      }}
    >
      <div className={styles.field}>
        <label className={styles.checkRow} htmlFor="graphql-enabled">
          <Switch
            id="graphql-enabled"
            checked={draft.enabled}
            disabled={readOnly}
            onCheckedChange={(next) => {
              setDraft((prev) => ({
                ...prev,
                enabled: next,
                playground: next ? prev.playground : false,
              }))
              setFieldErrors((prev) => clearFieldError(prev, 'enabled'))
            }}
          />
          <span>{t('system.graphqlEnabled')}</span>
        </label>
        <p className={styles.hint}>{t('system.graphqlEnabledHint')}</p>
        <FieldError messages={fieldErrors.enabled} />
      </div>
      <div className={styles.field}>
        <label className={styles.checkRow} htmlFor="graphql-playground">
          <Switch
            id="graphql-playground"
            checked={draft.playground}
            disabled={readOnly || !draft.enabled}
            onCheckedChange={(next) => {
              setDraft((prev) => ({ ...prev, playground: next }))
              setFieldErrors((prev) => clearFieldError(prev, 'playground'))
            }}
          />
          <span>{t('system.graphqlPlayground')}</span>
        </label>
        <p className={styles.hint}>{t('system.graphqlPlaygroundHint')}</p>
        <FieldError messages={fieldErrors.playground} />
      </div>
      {!readOnly && isDirty ? (
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
    queryFn: async () =>
      normalizeGraphqlSettings(await api<Partial<GraphqlSettings>>('/admin/api/settings/graphql')),
  })

  return (
    <Card id="system-graphql">
      <CardHeader>
        <CardTitle>{t('system.graphqlTitle')}</CardTitle>
        <CardDescription>{t('system.graphqlHint')}</CardDescription>
      </CardHeader>
      <CardContent>
        {!isOwner ? <p className={styles.hint}>{t('system.graphqlOwnerOnly')}</p> : null}
        {query.isError ? (
          <p className={styles.hint}>{t('system.graphqlUnavailable')}</p>
        ) : !query.data ? (
          <p className={styles.hint}>{t('common.loading')}</p>
        ) : (
          <GraphqlSettingsForm
            key={JSON.stringify(query.data)}
            initial={query.data}
            readOnly={!isOwner}
          />
        )}
      </CardContent>
    </Card>
  )
}
