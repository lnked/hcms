import { Skeleton } from '@/components/ui/skeleton'
import styles from './FormBlockSkeleton.module.css'

interface FormBlockSkeletonProps {
  fields?: number
}

export function FormBlockSkeleton({ fields = 4 }: FormBlockSkeletonProps) {
  return (
    <div className={styles.root} role="status" aria-busy="true">
      {Array.from({ length: fields }, (_, i) => (
        <div key={i} className={styles.field}>
          <Skeleton className={styles.label} />
          <Skeleton className={styles.control} />
        </div>
      ))}
      <Skeleton className={styles.submit} />
    </div>
  )
}
