import { useState } from 'react'
import { ChevronRight } from 'lucide-react'
import { CodeBlock } from '@/features/docs/CodeBlock'
import { useI18n } from '@/i18n'
import { cn } from '@/lib/utils'
import type { Resource } from '@/types/resource'
import { buildResourceFetchExample } from './buildResourceFetchExample'

interface ResourceFetchExampleProps {
  resource: Pick<Resource, 'endpoint' | 'settings'>
  className?: string
  showLabel?: boolean
  /** Collapsed by default; click the title to expand. */
  collapsible?: boolean
}

export function ResourceFetchExample({
  resource,
  className,
  showLabel = true,
  collapsible = false,
}: ResourceFetchExampleProps) {
  const { t } = useI18n()
  const [open, setOpen] = useState(!collapsible)
  const snippet = buildResourceFetchExample(resource)
  const title = t('resources.fetchExample')

  if (collapsible) {
    return (
      <div className={className}>
        <button
          type="button"
          className="inline-flex cursor-pointer items-center gap-1 text-xs font-medium text-muted-foreground hover:text-foreground"
          aria-expanded={open}
          onClick={() => setOpen((prev) => !prev)}
        >
          <ChevronRight
            className={cn('h-3.5 w-3.5 shrink-0 transition-transform', open && 'rotate-90')}
          />
          {title}
        </button>
        {open ? (
          <div className="mt-2">
            <CodeBlock code={snippet} language="js" />
          </div>
        ) : null}
      </div>
    )
  }

  return (
    <div className={className}>
      <CodeBlock
        code={snippet}
        language="js"
        label={showLabel ? title : undefined}
      />
    </div>
  )
}
