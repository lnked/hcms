import { cva, type VariantProps } from 'class-variance-authority'
import type { HTMLAttributes } from 'react'
import { cn } from '@/lib/utils'

const alertVariants = cva(
  'flex w-full items-start gap-3 rounded-lg border px-4 py-3 text-left text-sm',
  {
    variants: {
      variant: {
        default: 'border-border bg-muted/40 text-foreground',
        info: 'border-primary/30 bg-primary/5 text-foreground',
        destructive: 'border-destructive/40 bg-destructive/5 text-foreground',
      },
    },
    defaultVariants: { variant: 'default' },
  },
)

export function Alert({
  className,
  variant,
  ...props
}: HTMLAttributes<HTMLDivElement> & VariantProps<typeof alertVariants>) {
  return <div role="status" className={cn(alertVariants({ variant }), className)} {...props} />
}

export function AlertTitle({ className, ...props }: HTMLAttributes<HTMLParagraphElement>) {
  return <p className={cn('text-sm font-medium text-foreground', className)} {...props} />
}

export function AlertDescription({ className, ...props }: HTMLAttributes<HTMLParagraphElement>) {
  return <p className={cn('text-sm text-muted-foreground', className)} {...props} />
}
