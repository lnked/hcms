import { useRef, useState } from 'react'
import { Check, Copy } from 'lucide-react'
import { highlight, type LanguageName } from 'sugar-high'
import { Button } from '@/components/ui/button'
import { useI18n } from '@/i18n'
import { copyToClipboard } from '@/lib/clipboard'

type DocLanguage = 'bash' | 'js' | 'http'

const LANG_MAP: Record<DocLanguage, LanguageName> = {
  bash: 'shell',
  js: 'javascript',
  http: 'plaintext',
}

interface CodeBlockProps {
  code: string
  label?: string
  language?: DocLanguage
  editable?: boolean
  onChange?: (value: string) => void
  rows?: number
  id?: string
}

export function CodeBlock({
  code,
  label,
  language = 'js',
  editable = false,
  onChange,
  rows = 10,
  id,
}: CodeBlockProps) {
  const { t } = useI18n()
  const [copied, setCopied] = useState(false)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const html = editable ? '' : highlight(code, { lang: LANG_MAP[language] })

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

  const title = copied ? t('docs.copied') : t('docs.copy')

  return (
    <div className="space-y-1.5">
      {label ? (
        <label htmlFor={id} className="text-xs font-medium text-muted-foreground">
          {label}
        </label>
      ) : null}
      <div className="relative">
        <Button
          type="button"
          size="icon"
          variant="outline"
          className="absolute top-1/2 right-2 z-10 h-7 w-7 -translate-y-1/2 bg-background/90"
          onClick={() => void copy()}
          title={title}
          aria-label={title}
        >
          {copied ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />}
        </Button>
        {editable ? (
          <textarea
            id={id}
            rows={rows}
            value={code}
            spellCheck={false}
            onChange={(e) => onChange?.(e.target.value)}
            className="docs-code min-h-[200px] w-full resize-y rounded-md border bg-muted/40 p-3 pr-11 font-mono text-xs leading-[0.8] whitespace-pre shadow-none focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
          />
        ) : (
          <pre className="docs-code min-h-10 overflow-x-auto rounded-md border bg-muted/40 p-3 pr-11 font-mono text-xs leading-[0.8] whitespace-pre">
            <code dangerouslySetInnerHTML={{ __html: html }} />
          </pre>
        )}
      </div>
    </div>
  )
}
