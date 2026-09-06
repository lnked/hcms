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
}

export function CodeBlock({ code, label, language = 'js' }: CodeBlockProps) {
  const { t } = useI18n()
  const [copied, setCopied] = useState(false)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const html = highlight(code, { lang: LANG_MAP[language] })

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
      {label ? <p className="text-xs font-medium text-muted-foreground">{label}</p> : null}
      <div className="relative">
        <Button
          type="button"
          size="icon"
          variant="outline"
          className="absolute top-2 right-2 z-10 h-7 w-7 bg-background/90"
          onClick={() => void copy()}
          title={title}
          aria-label={title}
        >
          {copied ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />}
        </Button>
        <pre className="docs-code overflow-x-auto rounded-md border bg-muted/40 p-3 pr-11 font-mono text-xs whitespace-pre">
          <code dangerouslySetInnerHTML={{ __html: html }} />
        </pre>
      </div>
    </div>
  )
}
