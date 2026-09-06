import { CodeBlock } from '@/features/docs/CodeBlock'
import { useI18n } from '@/i18n'
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
  const snippet = buildResourceFetchExample(resource)

  return (
    <div className={className}>
      <CodeBlock
        code={snippet}
        language="js"
        label={showLabel ? t('resources.fetchExample') : undefined}
      />
    </div>
  )
}
