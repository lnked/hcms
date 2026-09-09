import * as React from 'react'
import { cn } from '@/lib/utils'
import styles from './Switch.module.css'

export interface SwitchProps extends Omit<
  React.ButtonHTMLAttributes<HTMLButtonElement>,
  'onChange' | 'value'
> {
  checked: boolean
  onCheckedChange: (checked: boolean) => void
}

export const Switch = React.forwardRef<HTMLButtonElement, SwitchProps>(
  ({ checked, onCheckedChange, className, disabled, ...props }, ref) => (
    <button
      ref={ref}
      type="button"
      role="switch"
      aria-checked={checked}
      disabled={disabled}
      onClick={() => onCheckedChange(!checked)}
      className={cn(styles.root, checked ? styles.rootChecked : styles.rootUnchecked, className)}
      {...props}
    >
      <span className={cn(styles.thumb, checked ? styles.thumbChecked : styles.thumbUnchecked)} />
    </button>
  ),
)
Switch.displayName = 'Switch'
