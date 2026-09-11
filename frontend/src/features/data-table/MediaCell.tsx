import { clsx } from 'clsx'
import styles from './MediaCell.module.css'
import type { MediaFieldValue, MediaItemRef } from '@/types/field'

const MAX_PREVIEWS = 3

interface MediaCellProps {
  value: unknown
  /** Schema field type: `image` previews even when the mime is unknown. */
  fieldType: string
}

function toItems(value: unknown): MediaFieldValue[] {
  if (value == null || value === '') return []
  const raw = Array.isArray(value) ? value : [value]
  const items: MediaFieldValue[] = []
  for (const entry of raw) {
    if (typeof entry === 'number' || (typeof entry === 'string' && /^\d+$/.test(entry))) {
      const id = Number(entry)
      if (id > 0) items.push({ id, rotation: 0, positions: {}, variants: {} })
      continue
    }
    if (entry == null || typeof entry !== 'object') continue
    const id = Number((entry as Record<string, unknown>).id)
    if (Number.isInteger(id) && id > 0) items.push(entry as MediaFieldValue)
  }
  return items
}

/** Smallest generated variant, so lists load thumbnails instead of full masters. */
function smallestVariant(item: MediaFieldValue): MediaItemRef | null {
  const refs = Object.values(item.variants ?? {}).filter(
    (v): v is MediaItemRef => typeof v === 'object' && v !== null && typeof v.width === 'number',
  )
  if (refs.length === 0) return null
  return refs.reduce((min, v) => ((v.width ?? 0) < (min.width ?? 0) ? v : min))
}

export function MediaCell({ value, fieldType }: MediaCellProps) {
  const items = toItems(value)
  if (items.length === 0) return <span className={clsx(styles.empty)}>—</span>

  const shown = items.slice(0, MAX_PREVIEWS)
  const hidden = items.length - shown.length

  return (
    <div className={clsx(styles.root)}>
      {shown.map((item, index) => {
        const mime = item.media?.mime ?? ''
        const isImage = mime ? mime.startsWith('image/') : fieldType === 'image'
        const href = item.media?.url ?? `/media/${item.id}`
        const variant = isImage ? smallestVariant(item) : null
        const name = item.media?.originalName ?? `#${item.id}`

        return (
          <a
            key={`${item.id}-${index}`}
            href={href}
            target="_blank"
            rel="noreferrer"
            title={name}
            className={clsx(styles.thumb)}
          >
            {isImage ? (
              <img
                src={variant?.url ?? href}
                alt=""
                loading="lazy"
                className={clsx(styles.thumbImg)}
                style={
                  variant || !item.rotation
                    ? undefined
                    : { transform: `rotate(${item.rotation}deg)` }
                }
              />
            ) : (
              <span className={clsx(styles.fileExt)}>{mime.split('/').pop() || 'file'}</span>
            )}
          </a>
        )
      })}
      {hidden > 0 ? <span className={clsx(styles.more)}>+{hidden}</span> : null}
    </div>
  )
}
