export type ToastKind = 'success' | 'error'

export interface ToastPayload {
  kind: ToastKind
  message: string
}

type ToastListener = (toast: ToastPayload) => void

const listeners = new Set<ToastListener>()

export function onToast(listener: ToastListener): () => void {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

export function showToast(kind: ToastKind, message: string): void {
  const text = message.trim()
  if (!text) return
  const payload: ToastPayload = { kind, message: text }
  for (const listener of listeners) {
    listener(payload)
  }
}

export function showSuccess(message: string): void {
  showToast('success', message)
}

export function showError(message: string): void {
  showToast('error', message)
}
