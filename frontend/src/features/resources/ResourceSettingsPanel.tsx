import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import type { Resource, ResourceSettings } from '@/types/resource'
import styles from './ResourceSettingsPanel.module.css'

interface ResourceSettingsPanelProps {
  resource: Resource
  onSaved?: () => void
}

const DEFAULT_SPAM: NonNullable<ResourceSettings['spam']> = {
  honeypotField: '',
  minSubmitMs: 0,
  rateLimitPerMinute: 0,
  requireCaptcha: false,
  maxLinks: 0,
  blocklist: [],
  rejectDuplicates: true,
}

function cloneSettings(settings: ResourceSettings): ResourceSettings {
  const spam = settings.spam ?? DEFAULT_SPAM
  return {
    ...settings,
    public: { ...settings.public },
    spam: {
      ...spam,
      blocklist: [...(spam.blocklist ?? [])],
    },
  }
}

function isPublicWriteUnprotected(settings: ResourceSettings): boolean {
  if (!settings.public.create) return false
  const spam = settings.spam
  if (!spam) return true
  return !spam.honeypotField && !spam.requireCaptcha && spam.minSubmitMs <= 0
}

export function ResourceSettingsPanel({ resource, onSaved }: ResourceSettingsPanelProps) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [settings, setSettings] = useState(() => cloneSettings(resource.settings))
  const [message, setMessage] = useState<string | null>(null)

  const save = useMutation({
    mutationFn: () =>
      api<Resource>(`/admin/api/resources/${resource.id}`, {
        method: 'PATCH',
        body: JSON.stringify({ settings }),
      }),
    onSuccess: (data) => {
      setSettings(cloneSettings(data.settings))
      setMessage(t('resources.settings.saved'))
      queryClient.setQueryData(['resource', resource.id], data)
      void queryClient.invalidateQueries({ queryKey: ['resources'] })
      onSaved?.()
    },
    onError: (err) => setMessage(err instanceof Error ? err.message : t('common.saveFailed')),
  })

  function patch(partial: Partial<ResourceSettings>) {
    setSettings((prev) => ({ ...prev, ...partial }))
    setMessage(null)
  }

  function patchPublic(key: keyof ResourceSettings['public'], value: boolean) {
    setSettings((prev) => ({
      ...prev,
      public: { ...prev.public, [key]: value },
    }))
    setMessage(null)
  }

  function patchSpam(partial: Partial<NonNullable<ResourceSettings['spam']>>) {
    setSettings((prev) => ({
      ...prev,
      spam: { ...(prev.spam ?? DEFAULT_SPAM), ...partial },
    }))
    setMessage(null)
  }

  function setDeleteStrategy(strategy: 'hard' | 'soft') {
    setSettings((prev) => ({
      ...prev,
      deleteStrategy: strategy,
      softDelete: strategy === 'soft',
    }))
    setMessage(null)
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('resources.settings.title')}</CardTitle>
        <CardDescription>{t('resources.settings.hint')}</CardDescription>
      </CardHeader>
      <CardContent className={styles.stack}>
        {isPublicWriteUnprotected(settings) ? (
          <p className={styles.warning}>{t('resources.settings.unprotectedWarning')}</p>
        ) : null}
        <div className={styles.grid2}>
          <label className={styles.checkLabel}>
            <input
              type="checkbox"
              checked={settings.apiEnabled}
              onChange={(e) => patch({ apiEnabled: e.target.checked })}
            />
            {t('resources.settings.apiEnabled')}
          </label>
          <label className={styles.checkLabel}>
            <input
              type="checkbox"
              checked={settings.pagination}
              onChange={(e) => patch({ pagination: e.target.checked })}
            />
            {t('resources.settings.pagination')}
          </label>
          <label className={styles.checkLabel}>
            <input
              type="checkbox"
              checked={settings.search}
              onChange={(e) => patch({ search: e.target.checked })}
            />
            {t('resources.settings.search')}
          </label>
          <label className={styles.checkLabel}>
            <input
              type="checkbox"
              checked={settings.sorting}
              onChange={(e) => patch({ sorting: e.target.checked })}
            />
            {t('resources.settings.sorting')}
          </label>
          <label className={styles.checkLabel}>
            <input
              type="checkbox"
              checked={settings.filtering}
              onChange={(e) => patch({ filtering: e.target.checked })}
            />
            {t('resources.settings.filtering')}
          </label>
        </div>

        <div className={styles.section}>
          <p className={styles.sectionTitle}>{t('resources.settings.publicAccess')}</p>
          <div className={styles.grid2}>
            <label className={styles.checkLabel}>
              <input
                type="checkbox"
                checked={settings.public.read}
                onChange={(e) => patchPublic('read', e.target.checked)}
              />
              {t('resources.settings.publicRead')}
            </label>
            <label className={styles.checkLabel}>
              <input
                type="checkbox"
                checked={settings.public.create}
                onChange={(e) => patchPublic('create', e.target.checked)}
              />
              {t('resources.settings.publicCreate')}
            </label>
            <label className={styles.checkLabel}>
              <input
                type="checkbox"
                checked={settings.public.update}
                onChange={(e) => patchPublic('update', e.target.checked)}
              />
              {t('resources.settings.publicUpdate')}
            </label>
            <label className={styles.checkLabel}>
              <input
                type="checkbox"
                checked={settings.public.delete}
                onChange={(e) => patchPublic('delete', e.target.checked)}
              />
              {t('resources.settings.publicDelete')}
            </label>
          </div>
        </div>

        <div className={styles.section}>
          <p className={styles.sectionTitle}>{t('resources.settings.spamTitle')}</p>
          <p className={styles.hint}>{t('resources.settings.spamHint')}</p>
          <div className={styles.grid2}>
            <div className={styles.field}>
              <Label htmlFor="honeypot">{t('resources.settings.honeypot')}</Label>
              <Input
                id="honeypot"
                value={settings.spam?.honeypotField ?? ''}
                onChange={(e) => patchSpam({ honeypotField: e.target.value })}
                placeholder="website"
              />
            </div>
            <div className={styles.field}>
              <Label htmlFor="min-submit">{t('resources.settings.minSubmitMs')}</Label>
              <Input
                id="min-submit"
                type="number"
                min={0}
                value={settings.spam?.minSubmitMs ?? 0}
                onChange={(e) => patchSpam({ minSubmitMs: Number(e.target.value) || 0 })}
              />
            </div>
            <div className={styles.field}>
              <Label htmlFor="spam-rl">{t('resources.settings.rateLimitPerMinute')}</Label>
              <Input
                id="spam-rl"
                type="number"
                min={0}
                value={settings.spam?.rateLimitPerMinute ?? 0}
                onChange={(e) => patchSpam({ rateLimitPerMinute: Number(e.target.value) || 0 })}
              />
            </div>
            <div className={styles.field}>
              <Label htmlFor="max-links">{t('resources.settings.maxLinks')}</Label>
              <Input
                id="max-links"
                type="number"
                min={0}
                value={settings.spam?.maxLinks ?? 0}
                onChange={(e) => patchSpam({ maxLinks: Number(e.target.value) || 0 })}
              />
            </div>
          </div>
          <label className={styles.checkLabel}>
            <input
              type="checkbox"
              checked={settings.spam?.requireCaptcha ?? false}
              onChange={(e) => patchSpam({ requireCaptcha: e.target.checked })}
            />
            {t('resources.settings.requireCaptcha')}
          </label>
          <label className={styles.checkLabel}>
            <input
              type="checkbox"
              checked={settings.spam?.rejectDuplicates ?? true}
              onChange={(e) => patchSpam({ rejectDuplicates: e.target.checked })}
            />
            {t('resources.settings.rejectDuplicates')}
          </label>
          <div className={styles.field}>
            <Label htmlFor="blocklist">{t('resources.settings.blocklist')}</Label>
            <Input
              id="blocklist"
              value={(settings.spam?.blocklist ?? []).join(', ')}
              onChange={(e) =>
                patchSpam({
                  blocklist: e.target.value
                    .split(',')
                    .map((s) => s.trim())
                    .filter(Boolean),
                })
              }
              placeholder="casino, crypto"
            />
          </div>
        </div>

        <div className={styles.deleteStrategy}>
          <Label htmlFor="delete-strategy">{t('resources.settings.deleteStrategy')}</Label>
          <Select
            id="delete-strategy"
            value={settings.deleteStrategy}
            onChange={(e) => setDeleteStrategy(e.target.value === 'soft' ? 'soft' : 'hard')}
          >
            <option value="hard">{t('resources.settings.deleteHard')}</option>
            <option value="soft">{t('resources.settings.deleteSoft')}</option>
          </Select>
        </div>

        <div className={styles.footer}>
          <Button disabled={save.isPending} onClick={() => save.mutate()}>
            {save.isPending ? t('common.saving') : t('common.save')}
          </Button>
          {message ? <p className={styles.message}>{message}</p> : null}
        </div>
      </CardContent>
    </Card>
  )
}
