/** Copy text; falls back when Clipboard API is unavailable (e.g. plain HTTP). */
import { showSuccess } from '@/lib/toast'

const COPIED_SENTINEL = '__hcms_copied__'

export function isCopiedToastMessage(message: string): boolean {
  return message === COPIED_SENTINEL
}

export async function copyToClipboard(text: string): Promise<void> {
  if (typeof navigator !== 'undefined' && navigator.clipboard?.writeText) {
    try {
      await navigator.clipboard.writeText(text)
      showSuccess(COPIED_SENTINEL)
      return
    } catch {
      // insecure context / permission — use legacy fallback
    }
  }

  const ta = document.createElement('textarea')
  ta.value = text
  ta.setAttribute('readonly', '')
  ta.style.position = 'fixed'
  ta.style.left = '-9999px'
  document.body.appendChild(ta)
  ta.select()
  const ok = document.execCommand('copy')
  document.body.removeChild(ta)
  if (!ok) {
    throw new Error('Copy failed')
  }
  showSuccess(COPIED_SENTINEL)
}
