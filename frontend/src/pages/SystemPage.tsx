import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { api } from '@/lib/api'
import type { SystemVersion } from '@/types/system'

interface UpdatePreview {
  from: string
  to: string
  updateAvailable: boolean
  hasBreaking: boolean
  backupReady: boolean
  changes: Array<{
    version: string
    type: string
    area?: string
    text: string
    migration?: string
  }>
  migrationNotes: string[]
}

interface UpdateStatus {
  state: string
  step: string | null
  error: string | null
}

export function SystemPage() {
  const queryClient = useQueryClient()
  const [ackBreaking, setAckBreaking] = useState(false)
  const [preview, setPreview] = useState<UpdatePreview | null>(null)
  const [message, setMessage] = useState<string | null>(null)

  const query = useQuery({
    queryKey: ['system-version'],
    queryFn: () => api<SystemVersion>('/admin/api/system/version'),
  })

  const status = useQuery({
    queryKey: ['update-status'],
    queryFn: () => api<UpdateStatus>('/admin/api/system/update/status'),
  })

  const loadPreview = useMutation({
    mutationFn: () =>
      api<UpdatePreview>('/admin/api/system/update/preview', { method: 'POST', body: '{}' }),
    onSuccess: (data) => {
      setPreview(data)
      setAckBreaking(false)
      setMessage(null)
    },
    onError: (err) => setMessage(err instanceof Error ? err.message : 'Preview failed'),
  })

  const runUpdate = useMutation({
    mutationFn: () =>
      api<UpdateStatus>('/admin/api/system/update/run', {
        method: 'POST',
        body: JSON.stringify({ acknowledgeBreaking: ackBreaking }),
      }),
    onSuccess: (data) => {
      setMessage(`Update ${data.state}${data.step ? ` (${data.step})` : ''}`)
      void queryClient.invalidateQueries({ queryKey: ['system-version'] })
      void queryClient.invalidateQueries({ queryKey: ['update-status'] })
    },
    onError: (err) => setMessage(err instanceof Error ? err.message : 'Update failed'),
  })

  const data = query.data
  const canUpdate = Boolean(preview?.updateAvailable && preview.backupReady)
  const needsAck = Boolean(preview?.hasBreaking)
  const runDisabled = !canUpdate || (needsAck && !ackBreaking) || runUpdate.isPending

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-semibold">System</h1>
      <Card>
        <CardHeader>
          <CardTitle>Version</CardTitle>
          <CardDescription>Product semver from VERSION / GitHub Releases.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-2 text-sm">
          <p>Current: {data?.current ?? '…'}</p>
          <p>Latest: {data?.latest ?? 'n/a'}</p>
          <p>Released: {data?.releasedAt ?? 'n/a'}</p>
          <p>Channel: {data?.channel ?? 'stable'}</p>
          <p>Update available: {data?.updateAvailable ? 'yes' : 'no'}</p>
          <p>Last update state: {status.data?.state ?? 'idle'}</p>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Update</CardTitle>
          <CardDescription>
            Downloads the latest release zip (sha256), preserves .env / uploads / lock, runs pending
            SQL migrations.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex flex-wrap gap-2">
            <Button
              variant="outline"
              disabled={loadPreview.isPending}
              onClick={() => loadPreview.mutate()}
            >
              {loadPreview.isPending ? 'Checking…' : 'Check for updates'}
            </Button>
            {preview?.updateAvailable ? (
              <Button disabled={runDisabled} onClick={() => runUpdate.mutate()}>
                {runUpdate.isPending ? 'Updating…' : `Update to v${preview.to}`}
              </Button>
            ) : null}
          </div>

          {preview?.hasBreaking ? (
            <div className="space-y-2 rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm">
              <p className="font-medium text-destructive">Breaking changes in this update</p>
              <ul className="list-disc space-y-1 pl-5">
                {preview.changes
                  .filter((c) => c.type === 'breaking')
                  .map((c, i) => (
                    <li key={i}>
                      <span className="font-mono text-xs">v{c.version}</span> — {c.text}
                      {c.migration ? (
                        <span className="block text-muted-foreground">
                          Migration: {c.migration}
                        </span>
                      ) : null}
                    </li>
                  ))}
              </ul>
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={ackBreaking}
                  onChange={(e) => setAckBreaking(e.target.checked)}
                />
                I understand the breaking changes
              </label>
            </div>
          ) : null}

          {preview && !preview.updateAvailable ? (
            <p className="text-sm text-muted-foreground">You are on the latest release.</p>
          ) : null}

          {preview?.changes && preview.changes.length > 0 ? (
            <div className="space-y-1 text-sm">
              <p className="font-medium">Changelog delta</p>
              <ul className="max-h-48 space-y-1 overflow-auto text-muted-foreground">
                {preview.changes.map((c, i) => (
                  <li key={i}>
                    <span className="font-mono text-xs">[{c.type}]</span> {c.text}
                  </li>
                ))}
              </ul>
            </div>
          ) : null}

          {message ? <p className="text-sm text-muted-foreground">{message}</p> : null}
          {status.data?.error ? (
            <p className="text-sm text-destructive">{status.data.error}</p>
          ) : null}
        </CardContent>
      </Card>
    </div>
  )
}
