import { ChevronDown } from 'lucide-react'
import * as React from 'react'
import { cn } from '@/lib/utils'
import { controlFieldClass } from './control'

export interface SelectProps extends React.SelectHTMLAttributes<HTMLSelectElement> {
  /** Applied to the positioning wrapper, e.g. to opt out of full width. */
  containerClassName?: string
}

export const Select = React.forwardRef<HTMLSelectElement, SelectProps>(
  ({ className, containerClassName, children, ...props }, ref) => (
    <div className={cn('relative w-full', containerClassName)}>
      <select
        ref={ref}
        className={cn(
          controlFieldClass,
          'cursor-pointer appearance-none pr-8 hover:border-muted-foreground/40',
          className,
        )}
        {...props}
      >
        {children}
      </select>
      <ChevronDown
        className="pointer-events-none absolute right-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground"
        aria-hidden
      />
    </div>
  ),
)
Select.displayName = 'Select'
