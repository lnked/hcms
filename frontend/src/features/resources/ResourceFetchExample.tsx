import { clsx } from 'clsx'
import { CodeBlock } from '@/features/docs/CodeBlock'
import { useI18n } from '@/i18n'
import type { Resource } from '@/types/resource'
import { buildResourceFetchExample } from './buildResourceFetchExample'
import styles from './ResourceFetchExample.module.css'

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
    <div className={clsx(styles.root, className)}>
      <CodeBlock
        code={snippet}
        language="js"
        label={showLabel ? t('resources.fetchExample') : undefined}
      />
    </div>
  )
}
