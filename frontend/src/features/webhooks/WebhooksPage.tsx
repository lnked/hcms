import { clsx } from 'clsx'
import { EmptyState } from '@/components/EmptyState'
import { TableSkeleton } from '@/components/skeletons'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { useI18n } from '@/i18n'
import { WebhookDeliveriesCard } from './WebhookDeliveriesCard'
import { WebhookFormDialog } from './WebhookFormDialog'
import styles from './WebhooksPage.module.css'
import { useWebhookDeliveries } from './useWebhookDeliveries'
import { useWebhookForm } from './useWebhookForm'
import { useWebhooksList } from './useWebhooksList'

export function WebhooksPage() {
  const { t } = useI18n()
  const list = useWebhooksList()
  const form = useWebhookForm((id) => list.setSelectedId(id))
  const deliveries = useWebhookDeliveries(list.selectedId)

  return (
    <div className={clsx(styles.root)}>
      <div className={clsx(styles.pageHeader)}>
        <div>
          <h1 className={clsx(styles.title)}>{t('webhooks.title')}</h1>
          <p className={clsx(styles.subtitle)}>{t('webhooks.subtitle')}</p>
        </div>
        <Button onClick={form.openCreate}>{t('webhooks.create')}</Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>{t('webhooks.listTitle')}</CardTitle>
          <CardDescription>{t('webhooks.listHint')}</CardDescription>
        </CardHeader>
        <CardContent>
          {list.webhooks.isLoading ? (
            <TableSkeleton columns={5} rows={5} />
          ) : (list.webhooks.data ?? []).length === 0 ? (
            <EmptyState title={t('webhooks.empty')} />
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('common.name')}</TableHead>
                  <TableHead>{t('webhooks.url')}</TableHead>
                  <TableHead>{t('webhooks.events')}</TableHead>
                  <TableHead>{t('common.status')}</TableHead>
                  <TableHead className={clsx(styles.alignRight)}>{t('common.actions')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {(list.webhooks.data ?? []).map((hook) => (
                  <TableRow
                    key={hook.id}
                    className={clsx(list.selectedId === hook.id && styles.rowSelected)}
                  >
                    <TableCell>
                      <button
                        type="button"
                        className={clsx(styles.nameBtn)}
                        onClick={() => list.selectWebhook(hook.id)}
                      >
                        {hook.name}
                      </button>
                      <div className={clsx(styles.mutedXs)}>
                        {hook.resourceId == null
                          ? t('webhooks.allResources')
                          : (list.resourceLabel.get(hook.resourceId) ?? `#${hook.resourceId}`)}
                      </div>
                    </TableCell>
                    <TableCell className={clsx(styles.urlCell)}>{hook.url}</TableCell>
                    <TableCell className={clsx(styles.mutedXs)}>{hook.events.join(', ')}</TableCell>
                    <TableCell>
                      {hook.status === 'active' ? (
                        <Badge>{t('webhooks.active')}</Badge>
                      ) : (
                        <Badge variant="destructive">{t('webhooks.disabled')}</Badge>
                      )}
                    </TableCell>
                    <TableCell className={clsx(styles.alignRight)}>
                      <div className={clsx(styles.rowActions)}>
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={list.test.isPending}
                          onClick={() => {
                            list.selectWebhook(hook.id)
                            list.test.mutate(hook.id)
                          }}
                        >
                          {t('webhooks.test')}
                        </Button>
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={list.toggleStatus.isPending}
                          onClick={() => list.toggleStatus.mutate(hook)}
                        >
                          {hook.status === 'active' ? t('webhooks.disable') : t('webhooks.enable')}
                        </Button>
                        <Button size="sm" variant="outline" onClick={() => form.openEdit(hook)}>
                          {t('common.edit')}
                        </Button>
                        <Button
                          size="sm"
                          variant="destructive"
                          disabled={list.remove.isPending}
                          onClick={() => {
                            if (confirm(t('webhooks.deleteConfirm', { name: hook.name }))) {
                              list.remove.mutate(hook.id)
                            }
                          }}
                        >
                          {t('common.delete')}
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
          {list.testResult ? <p className={clsx(styles.testResult)}>{list.testResult}</p> : null}
        </CardContent>
      </Card>

      {list.selectedId !== null ? <WebhookDeliveriesCard deliveries={deliveries} /> : null}

      <WebhookFormDialog form={form} resources={list.resources.data ?? []} />
    </div>
  )
}
