import { Skeleton } from '@/components/ui/skeleton'

interface FormBlockSkeletonProps {
  fields?: number
}

export function FormBlockSkeleton({ fields = 4 }: FormBlockSkeletonProps) {
  return (
    <div className="space-y-4" role="status" aria-busy="true">
      {Array.from({ length: fields }, (_, i) => (
        <div key={i} className="space-y-2">
          <Skeleton className="h-4 w-28" />
          <Skeleton className="h-9 w-full" />
        </div>
      ))}
      <Skeleton className="h-9 w-28" />
    </div>
  )
}
