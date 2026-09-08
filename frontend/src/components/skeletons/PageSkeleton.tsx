import { Skeleton } from '@/components/ui/skeleton'

/** Shown while a lazily loaded route chunk arrives; the shell stays put. */
export function PageSkeleton() {
  return (
    <div className="space-y-6" role="status" aria-busy="true">
      <div className="space-y-2">
        <Skeleton className="h-8 w-56" />
        <Skeleton className="h-4 w-80 max-w-full" />
      </div>
      <div className="space-y-3 rounded-lg border p-4">
        <Skeleton className="h-5 w-40" />
        <Skeleton className="h-4 w-full" />
        <Skeleton className="h-4 w-4/5 max-w-md" />
        <Skeleton className="h-4 w-3/5 max-w-sm" />
      </div>
    </div>
  )
}
