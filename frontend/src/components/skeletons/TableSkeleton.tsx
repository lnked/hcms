import { clsx } from 'clsx'
import { Skeleton } from '@/components/ui/skeleton'
import styles from './TableSkeleton.module.css'

interface TableSkeletonProps {
  columns?: number
  rows?: number
  className?: string
}

export function TableSkeleton({ columns = 5, rows = 6, className }: TableSkeletonProps) {
  return (
    <div className={className} role="status" aria-busy="true">
      <div className={styles.header}>
        {Array.from({ length: columns }, (_, i) => (
          <Skeleton key={`h-${i}`} className={styles.cell} />
        ))}
      </div>
      <div className={styles.rows}>
        {Array.from({ length: rows }, (_, row) => (
          <div key={row} className={styles.row}>
            {Array.from({ length: columns }, (_, col) => (
              <Skeleton
                key={`${row}-${col}`}
                className={clsx(col === columns - 1 ? styles.cellLast : styles.cell)}
              />
            ))}
          </div>
        ))}
      </div>
    </div>
  )
}
