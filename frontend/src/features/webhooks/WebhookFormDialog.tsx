import { clsx } from 'clsx'
import { FieldError } from '@/components/FieldError'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Form } from '@/components/ui/form'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { useI18n } from '@/i18n'
import { clearFieldError, hasFieldError } from '@/lib/formErrors'
import { WEBHOOK_EVENTS, WEBHOOK_PRESETS, type WebhookPresetId } from './presets'
import styles from './WebhooksPage.module.css'
import type { useWebhookForm } from './useWebhookForm'
import type { Resource } from '@/types/resource'

type WebhookForm = ReturnType<typeof useWebhookForm>

export function WebhookFormDialog({
  form,
  resources,
}: {
  form: WebhookForm
  resources: Resource[]
}) {
  const { t } = useI18n()
  const {
    open,
    setDialogOpen,
    isEdit,
    name,
    setName,
    url,
    setUrl,
    secret,
    setSecret,
    events,
    resourceId,
    setResourceId,
    status,
    setStatus,
    preset,
    payloadMode,
    setPayloadMode,
    headersText,
    setHeadersText,
    fieldErrors,
    setFieldErrors,
    create,
    update,
    busy,
    applyPreset,
    toggleEvent,
    submit,
    regenerateSecret,
  } = form

  return (
    <Dialog open={open} onOpenChange={setDialogOpen}>
      <DialogContent className={clsx(styles.dialogMd)}>
        <DialogHeader>
          <DialogTitle>{isEdit ? t('webhooks.editTitle') : t('webhooks.createTitle')}</DialogTitle>
          <DialogDescription>
            {isEdit ? t('webhooks.editHint') : t('webhooks.createHint')}
          </DialogDescription>
        </DialogHeader>

        <Form className={clsx(styles.stackMd)} onSubmit={submit}>
          <div className={clsx(styles.stackXs)}>
            <Label htmlFor="webhook-name">{t('common.name')}</Label>
            <Input
              id="webhook-name"
              value={name}
              aria-invalid={hasFieldError(fieldErrors, 'name') || undefined}
              onChange={(e) => {
                setName(e.target.value)
                setFieldErrors((prev) => clearFieldError(prev, 'name'))
              }}
              placeholder={t('webhooks.placeholderName')}
            />
            <FieldError messages={fieldErrors.name} />
          </div>
          <div className={clsx(styles.stackXs)}>
            <Label htmlFor="webhook-preset">{t('webhooks.preset')}</Label>
            <Select
              id="webhook-preset"
              value={preset}
              onChange={(e) => applyPreset(e.target.value as WebhookPresetId)}
            >
              {WEBHOOK_PRESETS.map((p) => (
                <option key={p.id} value={p.id}>
                  {t(`webhooks.preset.${p.id}`)}
                </option>
              ))}
            </Select>
            <p className={clsx(styles.mutedXs)}>{t('webhooks.presetHint')}</p>
          </div>
          <div className={clsx(styles.stackXs)}>
            <Label htmlFor="webhook-payload-mode">{t('webhooks.payloadMode')}</Label>
            <Select
              id="webhook-payload-mode"
              value={payloadMode}
              onChange={(e) =>
                setPayloadMode(e.target.value as 'hcms' | 'empty' | 'surrogate_keys')
              }
            >
              <option value="hcms">{t('webhooks.payloadMode.hcms')}</option>
              <option value="empty">{t('webhooks.payloadMode.empty')}</option>
              <option value="surrogate_keys">{t('webhooks.payloadMode.surrogate_keys')}</option>
            </Select>
          </div>
          <div className={clsx(styles.stackXs)}>
            <Label htmlFor="webhook-url">{t('webhooks.url')}</Label>
            <Input
              id="webhook-url"
              value={url}
              aria-invalid={hasFieldError(fieldErrors, 'url') || undefined}
              onChange={(e) => {
                setUrl(e.target.value)
                setFieldErrors((prev) => clearFieldError(prev, 'url'))
              }}
              placeholder="https://example.com/hooks/hcms"
            />
            <FieldError messages={fieldErrors.url} />
          </div>
          <div className={clsx(styles.stackXs)}>
            <div className={clsx(styles.fieldHeader)}>
              <Label htmlFor="webhook-secret">{t('webhooks.secret')}</Label>
              {!isEdit ? (
                <Button type="button" size="sm" variant="outline" onClick={regenerateSecret}>
                  {t('webhooks.regenerateSecret')}
                </Button>
              ) : null}
            </div>
            <Input
              id="webhook-secret"
              value={secret}
              aria-invalid={hasFieldError(fieldErrors, 'secret') || undefined}
              onChange={(e) => {
                setSecret(e.target.value)
                setFieldErrors((prev) => clearFieldError(prev, 'secret'))
              }}
              placeholder={isEdit ? t('webhooks.secretKeep') : undefined}
            />
            <FieldError messages={fieldErrors.secret} />
          </div>
          <div className={clsx(styles.stackXs)}>
            <Label htmlFor="webhook-headers">{t('webhooks.headers')}</Label>
            <Input
              id="webhook-headers"
              value={headersText}
              onChange={(e) => setHeadersText(e.target.value)}
              placeholder='{"Authorization":"Bearer …"}'
            />
            <p className={clsx(styles.mutedXs)}>{t('webhooks.headersHint')}</p>
            <FieldError messages={fieldErrors.headers} />
          </div>
          <div className={clsx(styles.stackXs)}>
            <Label>{t('webhooks.events')}</Label>
            <div className={clsx(styles.eventsBox)}>
              {WEBHOOK_EVENTS.map((event) => (
                <label key={event} className={clsx(styles.eventLabel)}>
                  <input
                    type="checkbox"
                    checked={events.includes(event)}
                    onChange={() => toggleEvent(event)}
                  />
                  <span className={clsx(styles.monoXs)}>{event}</span>
                </label>
              ))}
            </div>
            <FieldError messages={fieldErrors.events} />
          </div>
          <div className={clsx(styles.stackXs)}>
            <Label htmlFor="webhook-resource">{t('webhooks.resource')}</Label>
            <Select
              id="webhook-resource"
              value={resourceId ?? ''}
              aria-invalid={hasFieldError(fieldErrors, 'resourceId') || undefined}
              onChange={(e) => {
                const value = e.target.value
                setResourceId(value === '' ? null : Number(value))
                setFieldErrors((prev) => clearFieldError(prev, 'resourceId'))
              }}
            >
              <option value="">{t('webhooks.allResources')}</option>
              {resources.map((r) => (
                <option key={r.id} value={r.id}>
                  {r.label} ({r.slug})
                </option>
              ))}
            </Select>
            <FieldError messages={fieldErrors.resourceId} />
          </div>
          <div className={clsx(styles.stackXs)}>
            <Label htmlFor="webhook-status">{t('common.status')}</Label>
            <Select
              id="webhook-status"
              value={status}
              aria-invalid={hasFieldError(fieldErrors, 'status') || undefined}
              onChange={(e) => {
                setStatus(e.target.value === 'disabled' ? 'disabled' : 'active')
                setFieldErrors((prev) => clearFieldError(prev, 'status'))
              }}
            >
              <option value="active">{t('webhooks.active')}</option>
              <option value="disabled">{t('webhooks.disabled')}</option>
            </Select>
            <FieldError messages={fieldErrors.status} />
          </div>
          <div className={clsx(styles.actions)}>
            <Button variant="outline" onClick={() => setDialogOpen(false)}>
              {t('common.cancel')}
            </Button>
            {isEdit ? (
              <Button
                type="submit"
                disabled={!name.trim() || !url.trim() || events.length === 0 || busy}
              >
                {update.isPending ? t('common.saving') : t('common.save')}
              </Button>
            ) : (
              <Button
                type="submit"
                disabled={!name.trim() || !url.trim() || events.length === 0 || busy}
              >
                {create.isPending ? t('common.creating') : t('common.create')}
              </Button>
            )}
          </div>
        </Form>
      </DialogContent>
    </Dialog>
  )
}
