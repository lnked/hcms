import * as React from 'react'
import { cn } from '@/lib/utils'
import { controlFieldClass } from './control'

export const Input = React.forwardRef<
  HTMLInputElement,
  React.InputHTMLAttributes<HTMLInputElement>
>(({ className, type, ...props }, ref) => (
  <input
    type={type}
    className={cn(controlFieldClass, 'placeholder:text-muted-foreground', className)}
    ref={ref}
    {...props}
  />
))
Input.displayName = 'Input'
