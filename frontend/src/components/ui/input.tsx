import * as React from 'react'
import { cn } from '@/lib/utils'
import { controlFieldClass } from './control'
import styles from './Input.module.css'

export const Input = React.forwardRef<
  HTMLInputElement,
  React.InputHTMLAttributes<HTMLInputElement>
>(({ className, type, ...props }, ref) => (
  <input
    type={type}
    className={cn(controlFieldClass, styles.input, className)}
    ref={ref}
    {...props}
  />
))
Input.displayName = 'Input'
