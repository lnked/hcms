import { ChevronDown } from 'lucide-react'
import * as React from 'react'
import { cn } from '@/lib/utils'
import { controlFieldClass } from './control'
import styles from './Select.module.css'

export interface SelectProps extends React.SelectHTMLAttributes<HTMLSelectElement> {
  /** Applied to the positioning wrapper, e.g. to opt out of full width. */
  containerClassName?: string
}

export const Select = React.forwardRef<HTMLSelectElement, SelectProps>(
  ({ className, containerClassName, children, ...props }, ref) => (
    <div className={cn(styles.container, containerClassName)}>
      <select ref={ref} className={cn(controlFieldClass, styles.select, className)} {...props}>
        {children}
      </select>
      <ChevronDown className={styles.icon} aria-hidden />
    </div>
  ),
)
Select.displayName = 'Select'
