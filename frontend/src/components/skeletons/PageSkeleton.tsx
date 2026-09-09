import { Skeleton } from '@/components/ui/skeleton'
import styles from './PageSkeleton.module.css'

/** Shown while a lazily loaded route chunk arrives; the shell stays put. */
export function PageSkeleton() {
  return (
    <div className={styles.root} role="status" aria-busy="true">
      <div className={styles.header}>
        <Skeleton className={styles.title} />
        <Skeleton className={styles.subtitle} />
      </div>
      <div className={styles.block}>
        <Skeleton className={styles.blockTitle} />
        <Skeleton className={styles.lineFull} />
        <Skeleton className={styles.lineMd} />
        <Skeleton className={styles.lineSm} />
      </div>
    </div>
  )
}
