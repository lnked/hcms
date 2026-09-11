import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { useI18n } from '@/i18n'
import { ApiError, getToken, handleUnauthorized } from '@/lib/api'
import { showError } from '@/lib/toast'
import styles from './ResourceExportPanel.module.css'
import type { Resource } from '@/types/resource'

interface ResourceExportPanelProps {
  resource: Resource
}

export function ResourceExportPanel({ resource }: ResourceExportPanelProps) {
  const { t } = useI18n()
  const [includeData, setIncludeData] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const doExport = useMutation({
    mutationFn: async () => {
      const params = new URLSearchParams({
        includeData: includeData ? '1' : '0',
      })
      const path = `/admin/api/resources/${resource.id}/package/export?${params}`
      const headers = new Headers({ Accept: '*/*' })
      const token = getToken()
      if (token) headers.set('Authorization', `Bearer ${token}`)
      const response = await fetch(path, { headers })
      if (!response.ok) {
        if (response.status === 401) handleUnauthorized(path)
        let message = t('resources.package.exportFailed')
        try {
          const payload = (await response.json()) as { error?: { message?: string } }
          if (payload.error?.message) message = payload.error.message
        } catch {
          /* non-json */
        }
        throw new ApiError(response.status, 'ERROR', message)
      }
      const blob = await response.blob()
      const disposition = response.headers.get('Content-Disposition') ?? ''
      const match = /filename="([^"]+)"/.exec(disposition)
      const filename = match?.[1] ?? `${resource.slug}.cms-resource.json`
      const url = URL.createObjectURL(blob)
      const anchor = document.createElement('a')
      anchor.href = url
      anchor.download = filename
      anchor.click()
      URL.revokeObjectURL(url)
    },
    onSuccess: () => setError(null),
    onError: (err) => {
      const message = err instanceof Error ? err.message : t('resources.package.exportFailed')
      setError(message)
      showError(message)
    },
  })

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('resources.package.exportTitle')}</CardTitle>
        <CardDescription>{t('resources.package.exportHint')}</CardDescription>
      </CardHeader>
      <CardContent className={styles.stack}>
        <label className={styles.checkLabel} htmlFor="resource-export-include-data">
          <input
            id="resource-export-include-data"
            type="checkbox"
            className={styles.checkbox}
            checked={includeData}
            disabled={resource.status !== 'published'}
            onChange={(e) => {
              setIncludeData(e.target.checked)
              setError(null)
            }}
          />
          <span className={styles.labelStrong}>{t('resources.package.includeData')}</span>
        </label>
        <p className={styles.hint}>{t('resources.package.includeDataHint')}</p>

        <div className={styles.actions}>
          <Button onClick={() => doExport.mutate()} disabled={doExport.isPending}>
            {doExport.isPending
              ? t('resources.package.exporting')
              : t('resources.package.download')}
          </Button>
          {error ? <p className={styles.error}>{error}</p> : null}
        </div>
      </CardContent>
    </Card>
  )
}
