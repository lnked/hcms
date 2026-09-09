import { Skeleton } from '@/components/ui/skeleton'
import styles from './DetailPageSkeleton.module.css'

export function DetailPageSkeleton() {
  return (
    <div className={styles.root} role="status" aria-busy="true">
      <div className={styles.top}>
        <div className={styles.heading}>
          <Skeleton className={styles.title} />
          <Skeleton className={styles.subtitle} />
        </div>
        <div className={styles.actions}>
          <Skeleton className={styles.actionBtn} />
          <Skeleton className={styles.actionBtn} />
        </div>
      </div>
      <div className={styles.tabs}>
        {Array.from({ length: 5 }, (_, i) => (
          <Skeleton key={i} className={styles.tab} />
        ))}
      </div>
      <div className={styles.block}>
        <Skeleton className={styles.blockTitle} />
        <Skeleton className={styles.lineFull} />
        <Skeleton className={styles.lineMd} />
        <Skeleton className={styles.lineSm} />
        <div className={styles.tableWrap}>
          <TableishRows />
        </div>
      </div>
    </div>
  )
}

function TableishRows() {
  return (
    <div className={styles.rows}>
      {Array.from({ length: 4 }, (_, row) => (
        <div key={row} className={styles.row}>
          <Skeleton className={styles.cellGrow} />
          <Skeleton className={styles.cellGrow} />
          <Skeleton className={styles.cellFixed} />
        </div>
      ))}
    </div>
  )
}
