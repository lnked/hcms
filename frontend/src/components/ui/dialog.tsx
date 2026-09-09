import * as DialogPrimitive from '@radix-ui/react-dialog'
import { X } from 'lucide-react'
import { useState, type ComponentProps, type HTMLAttributes } from 'react'
import { UNSAFE_PortalProvider } from 'react-aria'
import { cn } from '@/lib/utils'
import styles from './Dialog.module.css'

export const Dialog = DialogPrimitive.Root
export const DialogTrigger = DialogPrimitive.Trigger
export const DialogClose = DialogPrimitive.Close

export function DialogContent({
  className,
  children,
  ...props
}: ComponentProps<typeof DialogPrimitive.Content>) {
  // React Aria overlays portal into document.body, which Radix marks `pointer-events: none`
  // and keeps outside its focus trap — clicks inside them are dead and they dismiss on the
  // first interaction. Hosting them in Content puts them back under the modal's own rules.
  const [content, setContent] = useState<HTMLDivElement | null>(null)

  return (
    <DialogPrimitive.Portal>
      <DialogPrimitive.Overlay className={styles.overlay} />
      {/*
        body { overflow: hidden } — Radix RemoveScroll
        container = Content: h-dvh + overflow-y-auto (must be Content, RemoveScroll only allows it)
        modal = inner card: overflow-hidden, height by content
      */}
      <DialogPrimitive.Content ref={setContent} className={styles.content} {...props}>
        <div className={styles.center}>
          <DialogPrimitive.Close aria-hidden tabIndex={-1} className={styles.backdropClose} />
          <div className={cn(styles.modal, className)}>
            <UNSAFE_PortalProvider getContainer={() => content}>{children}</UNSAFE_PortalProvider>
            <DialogPrimitive.Close className={styles.close}>
              <X className={styles.closeIcon} />
            </DialogPrimitive.Close>
          </div>
        </div>
      </DialogPrimitive.Content>
    </DialogPrimitive.Portal>
  )
}

export function DialogHeader({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div className={cn(styles.header, className)} {...props} />
}

export function DialogTitle({ className, ...props }: ComponentProps<typeof DialogPrimitive.Title>) {
  return <DialogPrimitive.Title className={cn(styles.title, className)} {...props} />
}

export function DialogDescription({
  className,
  ...props
}: ComponentProps<typeof DialogPrimitive.Description>) {
  return <DialogPrimitive.Description className={cn(styles.description, className)} {...props} />
}
