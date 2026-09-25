import { clsx } from 'clsx'
import { EmptyState } from '@/components/EmptyState'
import { TableSkeleton } from '@/components/skeletons'
import { Badge } from '@/components/ui/badge'
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
import styles from './WebhooksPage.module.css'
import type { UseQueryResult } from '@tanstack/react-query'
import type { WebhookDelivery } from './presets'

export function WebhookDeliveriesCard({
  deliveries,
}: {
  deliveries: UseQueryResult<WebhookDelivery[]>
}) {
  const { t } = useI18n()

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('webhooks.deliveriesTitle')}</CardTitle>
        <CardDescription>{t('webhooks.deliveriesHint')}</CardDescription>
      </CardHeader>
      <CardContent>
        {deliveries.isLoading ? (
          <TableSkeleton columns={6} rows={4} />
        ) : (deliveries.data ?? []).length === 0 ? (
          <EmptyState title={t('webhooks.deliveriesEmpty')} />
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{t('webhooks.event')}</TableHead>
                <TableHead>{t('common.status')}</TableHead>
                <TableHead>{t('webhooks.attempt')}</TableHead>
                <TableHead>{t('webhooks.responseCode')}</TableHead>
                <TableHead>{t('webhooks.duration')}</TableHead>
                <TableHead>{t('webhooks.createdAt')}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {(deliveries.data ?? []).map((d) => (
                <TableRow key={d.id}>
                  <TableCell className={clsx(styles.monoXs)}>{d.event}</TableCell>
                  <TableCell>
                    {d.status === 'success' ? (
                      <Badge>{d.status}</Badge>
                    ) : d.status === 'pending' ? (
                      <Badge variant="secondary">{d.status}</Badge>
                    ) : (
                      <Badge variant="destructive">{d.status}</Badge>
                    )}
                  </TableCell>
                  <TableCell>{d.attempt}</TableCell>
                  <TableCell>{d.responseCode ?? '—'}</TableCell>
                  <TableCell>{d.durationMs != null ? `${d.durationMs}ms` : '—'}</TableCell>
                  <TableCell className={clsx(styles.mutedXs)}>{d.createdAt}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </CardContent>
    </Card>
  )
}
