import type { ReactNode } from 'react'
import { AppLottie } from '@/components/AppLottie'
import { cn } from '@/lib/utils'

interface EmptyStateProps {
  title: string
  description?: string
  action?: ReactNode
  animationSrc?: string
  className?: string
}

const DEFAULT_ANIMATION = '/animations/empty.json'

export function EmptyState({
  title,
  description,
  action,
  animationSrc = DEFAULT_ANIMATION,
  className,
}: EmptyStateProps) {
  return (
    <div
      className={cn(
        'flex flex-col items-center justify-center gap-3 px-4 py-10 text-center',
        className,
      )}
    >
      <AppLottie src={animationSrc} />
      <div className="space-y-1">
        <p className="text-sm font-medium text-foreground">{title}</p>
        {description ? <p className="text-sm text-muted-foreground">{description}</p> : null}
      </div>
      {action ? <div className="pt-1">{action}</div> : null}
    </div>
  )
}
