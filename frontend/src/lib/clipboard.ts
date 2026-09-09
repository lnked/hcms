/** Copy text; falls back when Clipboard API is unavailable (e.g. plain HTTP). */
import { showSuccess } from '@/lib/toast'

const COPIED_SENTINEL = '__hcms_copied__'

export function isCopiedToastMessage(message: string): boolean {
  return message === COPIED_SENTINEL
}

function legacyCopy(text: string): boolean {
  const ta = document.createElement('textarea')
  ta.value = text
  ta.setAttribute('readonly', '')
  // Keep off-screen but focusable — opacity:0 + size 1 avoids some mobile quirks.
  ta.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;padding:0;border:0;opacity:0'
  // Radix Dialog sets aria-hidden on document.body siblings; copying from there fails.
  // Prefer the open dialog (or the focused subtree), then body as last resort.
  const active = document.activeElement
  const root =
    (active instanceof Element ? active.closest('[role="dialog"]') : null) ??
    document.querySelector('[role="dialog"]') ??
    document.body

  root.appendChild(ta)
  ta.focus()
  ta.select()
  ta.setSelectionRange(0, text.length)
  let ok = false
  try {
    ok = document.execCommand('copy')
  } finally {
    root.removeChild(ta)
  }
  return ok
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

  if (!legacyCopy(text)) {
    throw new Error('Copy failed')
  }
  showSuccess(COPIED_SENTINEL)
}
