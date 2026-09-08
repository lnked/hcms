import * as DialogPrimitive from '@radix-ui/react-dialog'
import { X } from 'lucide-react'
import { useState, type ComponentProps, type HTMLAttributes } from 'react'
import { UNSAFE_PortalProvider } from 'react-aria'
import { cn } from '@/lib/utils'

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
      <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-black/40" />
      {/*
        body { overflow: hidden } — Radix RemoveScroll
        container = Content: h-dvh + overflow-y-auto (must be Content, RemoveScroll only allows it)
        modal = inner card: overflow-hidden, height by content
      */}
      <DialogPrimitive.Content
        ref={setContent}
        className="fixed inset-0 z-50 box-border h-dvh overflow-y-auto bg-transparent p-0 py-5 shadow-none outline-none"
        {...props}
      >
        <div className="relative flex min-h-full items-center justify-center px-4">
          <DialogPrimitive.Close
            aria-hidden
            tabIndex={-1}
            className="absolute inset-0 cursor-default"
          />
          <div
            className={cn(
              'relative z-10 w-full max-w-lg overflow-hidden rounded-xl border bg-background p-6 shadow-lg',
              className,
            )}
          >
            <UNSAFE_PortalProvider getContainer={() => content}>{children}</UNSAFE_PortalProvider>
            <DialogPrimitive.Close className="absolute right-4 top-4 opacity-70 hover:opacity-100">
              <X className="h-4 w-4" />
            </DialogPrimitive.Close>
          </div>
        </div>
      </DialogPrimitive.Content>
    </DialogPrimitive.Portal>
  )
}

export function DialogHeader({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('mb-4 space-y-1', className)} {...props} />
}

export function DialogTitle({ className, ...props }: ComponentProps<typeof DialogPrimitive.Title>) {
  return <DialogPrimitive.Title className={cn('text-lg font-semibold', className)} {...props} />
}

export function DialogDescription({
  className,
  ...props
}: ComponentProps<typeof DialogPrimitive.Description>) {
  return (
    <DialogPrimitive.Description
      className={cn('text-sm text-muted-foreground', className)}
      {...props}
    />
  )
}
