import * as React from 'react'
import { cn } from '@/lib/utils'
import { controlAreaClass } from './control'
import styles from './Textarea.module.css'

export const Textarea = React.forwardRef<
  HTMLTextAreaElement,
  React.TextareaHTMLAttributes<HTMLTextAreaElement>
>(({ className, ...props }, ref) => (
  <textarea ref={ref} className={cn(controlAreaClass, styles.textarea, className)} {...props} />
))
Textarea.displayName = 'Textarea'
