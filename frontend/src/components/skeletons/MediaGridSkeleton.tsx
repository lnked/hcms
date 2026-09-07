import { Skeleton } from '@/components/ui/skeleton'

interface MediaGridSkeletonProps {
  count?: number
}

export function MediaGridSkeleton({ count = 16 }: MediaGridSkeletonProps) {
  return (
    <div
      className="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-8"
      role="status"
      aria-busy="true"
    >
      {Array.from({ length: count }, (_, i) => (
        <div key={i} className="overflow-hidden rounded-md border">
          <Skeleton className="aspect-square w-full rounded-none" />
          <div className="space-y-1.5 p-2">
            <Skeleton className="h-3 w-full" />
            <Skeleton className="h-2.5 w-12" />
          </div>
        </div>
      ))}
    </div>
  )
}
