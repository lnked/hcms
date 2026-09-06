import { useRef, useState } from 'react'
import { Copy } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useI18n } from '@/i18n'
import { copyToClipboard } from '@/lib/clipboard'
import type { Resource } from '@/types/resource'
import { buildResourceFetchExample } from './buildResourceFetchExample'

interface ResourceFetchExampleProps {
  resource: Pick<Resource, 'endpoint' | 'settings'>
  className?: string
  showLabel?: boolean
}

export function ResourceFetchExample({
  resource,
  className,
  showLabel = true,
}: ResourceFetchExampleProps) {
  const { t } = useI18n()
  const [copied, setCopied] = useState(false)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const snippet = buildResourceFetchExample(resource)

  async function copyExample() {
    try {
      await copyToClipboard(snippet)
      setCopied(true)
      if (timer.current) clearTimeout(timer.current)
      timer.current = setTimeout(() => setCopied(false), 1500)
    } catch {
      setCopied(false)
    }
  }

  return (
    <div className={className}>
      <div className={`mb-1.5 flex items-center gap-2 ${showLabel ? 'justify-between' : 'justify-end'}`}>
        {showLabel ? (
          <p className="text-xs font-medium text-muted-foreground">{t('resources.fetchExample')}</p>
        ) : null}
        <Button type="button" size="sm" variant="outline" onClick={() => void copyExample()}>
          <Copy className="mr-1.5 h-3.5 w-3.5" />
          {copied ? t('resources.fetchExampleCopied') : t('resources.fetchExampleCopy')}
        </Button>
      </div>
      <pre className="overflow-x-auto rounded-md border bg-muted/40 p-3 font-mono text-xs whitespace-pre">
        {snippet}
      </pre>
    </div>
  )
}
