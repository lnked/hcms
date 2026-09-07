import { InfoIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { cn } from '@/lib/utils'

interface EmptyStateProps {
  title: string
  description?: string
  action?: ReactNode
  className?: string
}

export function EmptyState({ title, description, action, className }: EmptyStateProps) {
  return (
    <Alert variant="info" className={cn('items-center', className)}>
      <InfoIcon className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden />
      <div className="flex-1 space-y-1">
        <AlertTitle>{title}</AlertTitle>
        {description ? <AlertDescription>{description}</AlertDescription> : null}
      </div>
      {action ? <div className="shrink-0">{action}</div> : null}
    </Alert>
  )
}
