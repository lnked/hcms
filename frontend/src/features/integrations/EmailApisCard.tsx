import { clsx } from 'clsx'
import { Plus, Trash2 } from 'lucide-react'
import { CodeBlock } from '@/components/CodeBlock'
import { EmptyState } from '@/components/EmptyState'
import { TableSkeleton } from '@/components/skeletons'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Form } from '@/components/ui/form'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { useI18n } from '@/i18n'
import { copyToClipboard } from '@/lib/clipboard'
import { buildEmailSendFetchExample } from './buildEmailSendFetchExample'
import { SEND_PATH } from './emailTypes'
import styles from './IntegrationsPage.module.css'
import type { useEmailApis } from './useEmailApis'

type EmailApis = ReturnType<typeof useEmailApis>

export function EmailApisCard({
  apis,
  onUseInPlayground,
}: {
  apis: EmailApis
  onUseInPlayground: (path: string) => void
}) {
  const { t } = useI18n()
  const {
    apisQuery,
    editingId,
    draft,
    setDraft,
    saveApi,
    deleteApi,
    startEdit,
    startCreate,
    cancelEdit,
  } = apis

  const copyPath = async (path: string) => {
    try {
      await copyToClipboard(path)
    } catch {
      // ignore
    }
  }

  return (
    <>
      <Card>
        <CardHeader>
          <CardTitle>{t('integrations.email.endpoints.title')}</CardTitle>
          <CardDescription>{t('integrations.email.endpoints.hint')}</CardDescription>
        </CardHeader>
        <CardContent className={clsx(styles.stack)}>
          <div className={clsx(styles.endpointRow)}>
            <code className={clsx(styles.pathCode)}>{SEND_PATH}</code>
            <Badge variant="secondary">POST</Badge>
            <Button size="sm" variant="outline" onClick={() => void copyPath(SEND_PATH)}>
              {t('integrations.email.endpoints.copy')}
            </Button>
            <Button size="sm" variant="ghost" onClick={() => onUseInPlayground(SEND_PATH)}>
              {t('integrations.email.endpoints.useInPlayground')}
            </Button>
          </div>
          <p className={clsx(styles.hint)}>{t('integrations.email.endpoints.authHint')}</p>
          <CodeBlock
            code={buildEmailSendFetchExample(SEND_PATH)}
            language="js"
            label={t('integrations.email.endpoints.example')}
          />
        </CardContent>
      </Card>

      <Card>
        <CardHeader className={clsx(styles.cardHeader)}>
          <div className={clsx(styles.cardIntro)}>
            <CardTitle>{t('integrations.email.apis.title')}</CardTitle>
            <CardDescription>{t('integrations.email.apis.hint')}</CardDescription>
          </div>
          <Button size="sm" onClick={startCreate}>
            <Plus className={clsx(styles.iconSm)} />
            {t('integrations.email.apis.create')}
          </Button>
        </CardHeader>
        <CardContent className={clsx(styles.stackMd)}>
          {apisQuery.isLoading ? (
            <TableSkeleton columns={3} rows={4} />
          ) : (apisQuery.data ?? []).length === 0 && editingId === null ? (
            <EmptyState title={t('integrations.email.apis.empty')} />
          ) : (
            <div className={clsx(styles.apiList)}>
              {(apisQuery.data ?? []).map((item) => (
                <div key={item.id} className={clsx(styles.apiItem)}>
                  <div className={clsx(styles.apiItemHeader)}>
                    <div className={clsx(styles.apiItemMeta)}>
                      <div className={clsx(styles.apiItemTitleRow)}>
                        <span className={clsx(styles.apiLabel)}>{item.label}</span>
                        <Badge variant={item.enabled ? 'default' : 'secondary'}>
                          {item.enabled ? t('common.enabled') : t('common.disabled')}
                        </Badge>
                      </div>
                      <button
                        type="button"
                        className={clsx(styles.pathBtn)}
                        onClick={() => void copyPath(item.path)}
                        title={t('integrations.email.endpoints.copy')}
                      >
                        {item.path}
                      </button>
                    </div>
                    <div className={clsx(styles.actionsRow)}>
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => onUseInPlayground(item.path)}
                      >
                        {t('integrations.email.endpoints.useInPlayground')}
                      </Button>
                      <Button size="sm" variant="outline" onClick={() => startEdit(item)}>
                        {t('common.edit')}
                      </Button>
                      <Button
                        size="sm"
                        variant="destructive"
                        disabled={deleteApi.isPending}
                        onClick={() => {
                          if (
                            confirm(t('integrations.email.apis.deleteConfirm', { slug: item.slug }))
                          ) {
                            deleteApi.mutate(item.id)
                          }
                        }}
                      >
                        <Trash2 className={clsx(styles.iconSm)} />
                      </Button>
                    </div>
                  </div>
                  <CodeBlock
                    code={buildEmailSendFetchExample(item.path, { withVars: true })}
                    language="js"
                    label={t('integrations.email.endpoints.example')}
                  />
                </div>
              ))}
            </div>
          )}

          {editingId !== null ? (
            <Form className={clsx(styles.editPanel)} onSubmit={() => saveApi.mutate()}>
              <div className={clsx(styles.grid2Sm)}>
                <div className={clsx(styles.field)}>
                  <Label htmlFor="email-api-slug">{t('common.slug')}</Label>
                  <Input
                    id="email-api-slug"
                    value={draft.slug}
                    onChange={(e) => setDraft((d) => ({ ...d, slug: e.target.value }))}
                    placeholder="welcome"
                  />
                </div>
                <div className={clsx(styles.field)}>
                  <Label htmlFor="email-api-label">{t('common.label')}</Label>
                  <Input
                    id="email-api-label"
                    value={draft.label}
                    onChange={(e) => setDraft((d) => ({ ...d, label: e.target.value }))}
                    placeholder="Welcome email"
                  />
                </div>
              </div>
              <label className={clsx(styles.checkRowCenter)}>
                <input
                  type="checkbox"
                  checked={draft.enabled}
                  onChange={(e) => setDraft((d) => ({ ...d, enabled: e.target.checked }))}
                />
                {t('common.enabled')}
              </label>
              <label className={clsx(styles.checkRowCenter)}>
                <input
                  type="checkbox"
                  checked={draft.settings.allowFromOverride}
                  onChange={(e) =>
                    setDraft((d) => ({
                      ...d,
                      settings: { ...d.settings, allowFromOverride: e.target.checked },
                    }))
                  }
                />
                {t('integrations.email.apis.allowFromOverride')}
              </label>
              <div className={clsx(styles.field)}>
                <Label htmlFor="email-api-subject">
                  {t('integrations.email.apis.defaultSubject')}
                </Label>
                <Input
                  id="email-api-subject"
                  value={draft.defaults.subject}
                  onChange={(e) =>
                    setDraft((d) => ({
                      ...d,
                      defaults: { ...d.defaults, subject: e.target.value },
                    }))
                  }
                  placeholder="Welcome, {{name}}"
                />
              </div>
              <div className={clsx(styles.field)}>
                <Label htmlFor="email-api-html">{t('integrations.email.apis.defaultHtml')}</Label>
                <Textarea
                  id="email-api-html"
                  rows={4}
                  value={draft.defaults.html}
                  onChange={(e) =>
                    setDraft((d) => ({ ...d, defaults: { ...d.defaults, html: e.target.value } }))
                  }
                  className={clsx(styles.monoTextareaHtml)}
                  placeholder="<p>Hello {{name}}</p>"
                />
              </div>
              <div className={clsx(styles.field)}>
                <Label htmlFor="email-api-text">{t('integrations.email.apis.defaultText')}</Label>
                <Textarea
                  id="email-api-text"
                  rows={3}
                  value={draft.defaults.text}
                  onChange={(e) =>
                    setDraft((d) => ({ ...d, defaults: { ...d.defaults, text: e.target.value } }))
                  }
                  className={clsx(styles.monoTextareaText)}
                  placeholder="Hello {{name}}"
                />
              </div>
              <p className={clsx(styles.hint)}>{t('integrations.email.apis.varsHint')}</p>
              <div className={clsx(styles.actionsRow)}>
                <Button
                  type="submit"
                  disabled={saveApi.isPending || !draft.slug.trim() || !draft.label.trim()}
                >
                  {saveApi.isPending ? t('common.saving') : t('common.save')}
                </Button>
                <Button variant="outline" onClick={cancelEdit}>
                  {t('common.cancel')}
                </Button>
              </div>
            </Form>
          ) : null}
        </CardContent>
      </Card>
    </>
  )
}
