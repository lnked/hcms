import { cn } from '@/lib/utils'

export type AnchorPosition = 'nw' | 'n' | 'ne' | 'w' | 'c' | 'e' | 'sw' | 's' | 'se'

export const ANCHOR_POSITIONS: AnchorPosition[] = ['nw', 'n', 'ne', 'w', 'c', 'e', 'sw', 's', 'se']

interface AnchorGridProps {
  value: string
  disabled?: boolean
  onChange: (position: AnchorPosition) => void
  className?: string
  title?: string
}

export function AnchorGrid({ value, disabled, onChange, className, title }: AnchorGridProps) {
  const current = (
    ANCHOR_POSITIONS.includes(value as AnchorPosition) ? value : 'c'
  ) as AnchorPosition

  return (
    <div
      className={cn('inline-grid grid-cols-3 gap-0.5 rounded border border-input p-0.5', className)}
      role="group"
      title={title}
      aria-label={title ?? 'Image anchor'}
    >
      {ANCHOR_POSITIONS.map((pos) => (
        <button
          key={pos}
          type="button"
          disabled={disabled}
          aria-pressed={current === pos}
          className={cn(
            'h-5 w-5 rounded-sm transition-colors',
            current === pos ? 'bg-primary' : 'bg-muted hover:bg-muted-foreground/25',
            disabled && 'opacity-50',
          )}
          onClick={() => onChange(pos)}
        />
      ))}
    </div>
  )
}
