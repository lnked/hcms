import type { KeyboardEvent } from 'react'
import { cn } from '@/lib/utils'

export type AnchorPosition = 'nw' | 'n' | 'ne' | 'w' | 'c' | 'e' | 'sw' | 's' | 'se'

/** Порядок перебора по клику: центр, затем по кругу с левого верхнего угла. */
export const ANCHOR_CYCLE: AnchorPosition[] = ['c', 'nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w']

const GRID = 36
const CELL = GRID / 3
const AXIS = [CELL / 2, CELL * 1.5, CELL * 2.5]
const DIAGONAL_STEPS = [-1, 1]

const CELLS: Record<AnchorPosition, readonly [number, number]> = {
  nw: [0, 0],
  n: [0, 1],
  ne: [0, 2],
  w: [1, 0],
  c: [1, 1],
  e: [1, 2],
  sw: [2, 0],
  s: [2, 1],
  se: [2, 2],
}

const POSITION_BY_CELL = new Map<string, AnchorPosition>(
  (Object.entries(CELLS) as [AnchorPosition, readonly [number, number]][]).map(
    ([position, [row, col]]) => [`${row},${col}`, position],
  ),
)

/** Поворот базовой стрелки (смотрит вправо-вниз) по вектору «столбец, строка». */
const ARROW_ROTATION: Record<string, number> = {
  '1,1': 0,
  '-1,1': 90,
  '-1,-1': 180,
  '1,-1': 270,
}

const KEY_STEPS: Record<string, readonly [number, number]> = {
  ArrowUp: [-1, 0],
  ArrowDown: [1, 0],
  ArrowLeft: [0, -1],
  ArrowRight: [0, 1],
}

const clampCell = (value: number) => Math.min(2, Math.max(0, value))

function normalize(value: string): AnchorPosition {
  return value in CELLS ? (value as AnchorPosition) : 'c'
}

/** Стрелки стоят в диагональных соседях якоря и смотрят на точку. */
function arrowsFor(position: AnchorPosition) {
  const [row, col] = CELLS[position]

  return DIAGONAL_STEPS.flatMap((rowStep) =>
    DIAGONAL_STEPS.flatMap((colStep) => {
      const arrowRow = row + rowStep
      const arrowCol = col + colStep

      if (arrowRow < 0 || arrowRow > 2 || arrowCol < 0 || arrowCol > 2) return []

      return [
        {
          key: `${arrowRow},${arrowCol}`,
          x: AXIS[arrowCol],
          y: AXIS[arrowRow],
          angle: ARROW_ROTATION[`${-colStep},${-rowStep}`],
        },
      ]
    }),
  )
}

interface AnchorPickerProps {
  value: string
  disabled?: boolean
  onChange: (position: AnchorPosition) => void
  className?: string
  title?: string
}

export function AnchorPicker({ value, disabled, onChange, className, title }: AnchorPickerProps) {
  const current = normalize(value)

  const shift = (step: number) => {
    const index = ANCHOR_CYCLE.indexOf(current)
    const next = ANCHOR_CYCLE[(index + step + ANCHOR_CYCLE.length) % ANCHOR_CYCLE.length]
    if (next !== current) onChange(next)
  }

  const handleKeyDown = (event: KeyboardEvent<HTMLButtonElement>) => {
    const step = KEY_STEPS[event.key]
    if (!step) return

    event.preventDefault()
    const [row, col] = CELLS[current]
    const next = POSITION_BY_CELL.get(`${clampCell(row + step[0])},${clampCell(col + step[1])}`)
    if (next && next !== current) onChange(next)
  }

  return (
    <button
      type="button"
      disabled={disabled}
      title={title}
      aria-label={title ?? 'Image anchor'}
      data-position={current}
      className={cn(
        'flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-input p-0.5',
        'bg-transparent text-foreground transition-colors hover:bg-muted',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
        'disabled:pointer-events-none disabled:opacity-50',
        className,
      )}
      onClick={(event) => shift(event.shiftKey ? -1 : 1)}
      onKeyDown={handleKeyDown}
    >
      <svg viewBox={`0 0 ${GRID} ${GRID}`} className="h-full w-full" aria-hidden="true">
        <g className="text-border" stroke="currentColor" strokeWidth={1}>
          <line x1={CELL} y1={2} x2={CELL} y2={GRID - 2} />
          <line x1={CELL * 2} y1={2} x2={CELL * 2} y2={GRID - 2} />
          <line x1={2} y1={CELL} x2={GRID - 2} y2={CELL} />
          <line x1={2} y1={CELL * 2} x2={GRID - 2} y2={CELL * 2} />
        </g>

        {arrowsFor(current).map((arrow) => (
          <g key={arrow.key} transform={`translate(${arrow.x} ${arrow.y}) rotate(${arrow.angle})`}>
            <line
              x1={-4.4}
              y1={-4.4}
              x2={1}
              y2={1}
              stroke="currentColor"
              strokeWidth={2.4}
              strokeLinecap="butt"
            />
            <polygon points="4.4,4.4 4.4,-0.8 -0.8,4.4" fill="currentColor" />
          </g>
        ))}

        <circle
          cx={AXIS[CELLS[current][1]]}
          cy={AXIS[CELLS[current][0]]}
          r={3.6}
          fill="currentColor"
        />
      </svg>
    </button>
  )
}
