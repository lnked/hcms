import { useRef, useState } from 'react'
import { Copy } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useI18n } from '@/i18n'
import { copyToClipboard } from '@/lib/clipboard'

interface CodeBlockProps {
  code: string
  label?: string
}

export function CodeBlock({ code, label }: CodeBlockProps) {
  const { t } = useI18n()
  const [copied, setCopied] = useState(false)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)

  async function copy() {
    try {
      await copyToClipboard(code)
      setCopied(true)
      if (timer.current) clearTimeout(timer.current)
      timer.current = setTimeout(() => setCopied(false), 1500)
    } catch {
      setCopied(false)
    }
  }

  return (
    <div>
      <div className="mb-1.5 flex items-center justify-between gap-2">
        {label ? (
          <p className="text-xs font-medium text-muted-foreground">{label}</p>
        ) : (
          <span />
        )}
        <Button type="button" size="sm" variant="outline" onClick={() => void copy()}>
          <Copy className="mr-1.5 h-3.5 w-3.5" />
          {copied ? t('docs.copied') : t('docs.copy')}
        </Button>
      </div>
      <pre className="overflow-x-auto rounded-md border bg-muted/40 p-3 font-mono text-xs whitespace-pre">
        {code}
      </pre>
    </div>
  )
}
