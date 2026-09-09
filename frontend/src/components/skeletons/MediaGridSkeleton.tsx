import { Skeleton } from '@/components/ui/skeleton'
import styles from './MediaGridSkeleton.module.css'

interface MediaGridSkeletonProps {
  count?: number
}

export function MediaGridSkeleton({ count = 16 }: MediaGridSkeletonProps) {
  return (
    <div className={styles.grid} role="status" aria-busy="true">
      {Array.from({ length: count }, (_, i) => (
        <div key={i} className={styles.card}>
          <Skeleton className={styles.thumb} />
          <div className={styles.meta}>
            <Skeleton className={styles.name} />
            <Skeleton className={styles.size} />
          </div>
        </div>
      ))}
    </div>
  )
}
