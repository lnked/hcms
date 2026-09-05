import { useRef, useState } from 'react'
import { Button } from '@/components/ui/button'
import { apiUpload } from '@/lib/api'
import type { MediaItem } from '@/types/media'

interface MediaFieldPickerProps {
  id: string
  value: unknown
  disabled?: boolean
  accept?: string
  onChange: (mediaId: number | null) => void
}

export function MediaFieldPicker({ id, value, disabled, accept, onChange }: MediaFieldPickerProps) {
  const inputRef = useRef<HTMLInputElement>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const mediaId =
    typeof value === 'number' ? value : value == null || value === '' ? null : Number(value)

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center gap-2">
        <input
          ref={inputRef}
          id={id}
          type="file"
          accept={accept}
          className="hidden"
          disabled={disabled || busy}
          onChange={async (e) => {
            const file = e.target.files?.[0]
            e.target.value = ''
            if (!file) return
            setBusy(true)
            setError(null)
            try {
              const item = await apiUpload<MediaItem>('/admin/api/media', file)
              onChange(item.id)
            } catch (err) {
              setError(err instanceof Error ? err.message : 'Upload failed')
            } finally {
              setBusy(false)
            }
          }}
        />
        <Button
          type="button"
          size="sm"
          variant="outline"
          disabled={disabled || busy}
          onClick={() => inputRef.current?.click()}
        >
          {busy ? 'Uploading…' : 'Upload'}
        </Button>
        {mediaId != null && !Number.isNaN(mediaId) ? (
          <>
            <a
              href={`/media/${mediaId}`}
              target="_blank"
              rel="noreferrer"
              className="text-sm text-primary underline-offset-4 hover:underline"
            >
              #{mediaId}
            </a>
            <Button
              type="button"
              size="sm"
              variant="ghost"
              disabled={disabled}
              onClick={() => onChange(null)}
            >
              Clear
            </Button>
          </>
        ) : (
          <span className="text-sm text-muted-foreground">No file</span>
        )}
      </div>
      {error ? <p className="text-xs text-destructive">{error}</p> : null}
    </div>
  )
}
