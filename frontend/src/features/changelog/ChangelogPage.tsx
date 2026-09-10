import { useEffect, useRef } from 'react'
import { useInfiniteQuery } from '@tanstack/react-query'
import { clsx } from 'clsx'
import { EmptyState } from '@/components/EmptyState'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { FormBlockSkeleton } from '@/components/skeletons'
import { useI18n } from '@/i18n'
import { apiPage } from '@/lib/api'
import type { ChangeType, Release } from '@/types/system'
import styles from './ChangelogPage.module.css'

const PAGE_SIZE = 20

const typeVariant: Record<ChangeType, 'default' | 'secondary' | 'destructive' | 'outline'> = {
  added: 'default',
  changed: 'secondary',
  deprecated: 'outline',
  removed: 'outline',
  fixed: 'secondary',
  security: 'destructive',
  breaking: 'destructive',
}

export function ChangelogPage() {
  const { t } = useI18n()
  const sentinelRef = useRef<HTMLDivElement | null>(null)

  const { data, isLoading, isError, hasNextPage, isFetchingNextPage, fetchNextPage } =
    useInfiniteQuery({
      queryKey: ['changelog', 'infinite'],
      queryFn: ({ pageParam }) =>
        apiPage<Release>(`/admin/api/system/changelog?page=${pageParam}&limit=${PAGE_SIZE}`),
      initialPageParam: 1,
      getNextPageParam: (last) =>
        last.meta.page < last.meta.totalPages ? last.meta.page + 1 : undefined,
    })

  const releases = data?.pages.flatMap((page) => page.data) ?? []

  useEffect(() => {
    const node = sentinelRef.current
    if (!node || !hasNextPage) {
      return
    }

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting) && !isFetchingNextPage) {
          void fetchNextPage()
        }
      },
      { rootMargin: '200px 0px' },
    )

    observer.observe(node)
    return () => observer.disconnect()
  }, [hasNextPage, isFetchingNextPage, fetchNextPage])

  return (
    <div className={clsx(styles.root)}>
      <div className={clsx(styles.header)}>
        <h1 className={clsx(styles.title)}>{t('changelog.title')}</h1>
        <p className={clsx(styles.subtitle)}>{t('changelog.subtitle')}</p>
      </div>
      {isLoading ? (
        <FormBlockSkeleton fields={4} />
      ) : isError ? (
        <EmptyState title={t('common.loadError')} description={t('changelog.loadError')} />
      ) : releases.length === 0 ? (
        <EmptyState title={t('changelog.empty')} />
      ) : (
        <>
          {releases.map((release) => (
            <Card key={release.version}>
              <CardHeader>
                <CardTitle className={clsx(styles.releaseTitle)}>
                  v{release.version}
                  {release.title ? (
                    <span className={clsx(styles.releaseTitleMuted)}>— {release.title}</span>
                  ) : null}
                  <Badge variant="outline">{release.channel}</Badge>
                </CardTitle>
              </CardHeader>
              <CardContent>
                <ul className={clsx(styles.changeList)}>
                  {release.changes.map((change) => (
                    <li key={change.text} className={clsx(styles.changeItem)}>
                      <Badge variant={typeVariant[change.type]}>{change.type}</Badge>
                      <span>{change.text}</span>
                    </li>
                  ))}
                </ul>
              </CardContent>
            </Card>
          ))}
          <div ref={sentinelRef} className={clsx(styles.sentinel)} aria-hidden={!hasNextPage}>
            {isFetchingNextPage ? (
              <p className={clsx(styles.loadingMore)}>{t('common.loading')}</p>
            ) : null}
          </div>
        </>
      )}
    </div>
  )
}
