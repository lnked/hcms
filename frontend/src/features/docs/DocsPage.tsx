import { Link, Navigate, NavLink, useParams } from 'react-router-dom'
import { ExternalLink } from 'lucide-react'
import { useI18n } from '@/i18n'
import { cn } from '@/lib/utils'
import { CodeBlock } from './CodeBlock'
import {
  DEFAULT_CHAPTER,
  getChapter,
  getChapters,
  isChapterId,
  type DocLink,
} from './chapters'

function DocNavLink({ link }: { link: DocLink }) {
  if (link.external) {
    return (
      <a
        href={link.href}
        target="_blank"
        rel="noopener noreferrer"
        className="inline-flex items-center gap-1 text-sm text-primary hover:underline"
      >
        {link.label}
        <ExternalLink className="h-3.5 w-3.5" />
      </a>
    )
  }

  return (
    <Link to={link.href} className="text-sm text-primary hover:underline">
      {link.label}
    </Link>
  )
}

export function DocsPage() {
  const { t, locale } = useI18n()
  const { chapter: chapterParam } = useParams()
  const chapters = getChapters(locale)

  if (!chapterParam) {
    return <Navigate to={`/docs/${DEFAULT_CHAPTER}`} replace />
  }

  if (!isChapterId(chapterParam)) {
    return <Navigate to={`/docs/${DEFAULT_CHAPTER}`} replace />
  }

  const chapter = getChapter(locale, chapterParam)
  if (!chapter) {
    return <Navigate to={`/docs/${DEFAULT_CHAPTER}`} replace />
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">{t('docs.title')}</h1>
        <p className="text-sm text-muted-foreground">{t('docs.subtitle')}</p>
      </div>

      <div className="flex flex-col gap-8 lg:flex-row lg:items-start">
        <nav
          aria-label={t('docs.toc')}
          className="shrink-0 lg:sticky lg:top-8 lg:w-56"
        >
          <p className="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">
            {t('docs.toc')}
          </p>
          <ul className="flex gap-1 overflow-x-auto pb-1 lg:flex-col lg:overflow-visible lg:pb-0">
            {chapters.map((item) => (
              <li key={item.id} className="shrink-0">
                <NavLink
                  to={`/docs/${item.id}`}
                  className={({ isActive }) =>
                    cn(
                      'block rounded-md px-3 py-2 text-sm whitespace-nowrap hover:bg-accent',
                      isActive && 'bg-accent font-medium',
                    )
                  }
                >
                  {item.title}
                </NavLink>
              </li>
            ))}
          </ul>
        </nav>

        <article className="min-w-0 flex-1 space-y-8">
          <h2 className="text-xl font-semibold">{chapter.title}</h2>
          {chapter.sections.map((section, index) => (
            <section key={`${chapter.id}-${index}`} className="space-y-3">
              {section.heading ? (
                <h3 className="text-base font-medium">{section.heading}</h3>
              ) : null}
              {section.paragraphs.map((paragraph) => (
                <p key={paragraph} className="text-sm leading-relaxed text-muted-foreground">
                  {paragraph}
                </p>
              ))}
              {section.samples?.map((sample) => (
                <CodeBlock
                  key={`${sample.language}-${sample.label ?? ''}-${sample.code.slice(0, 40)}`}
                  code={sample.code}
                  label={sample.label}
                />
              ))}
              {section.links && section.links.length > 0 ? (
                <ul className="flex flex-wrap gap-x-4 gap-y-2">
                  {section.links.map((link) => (
                    <li key={`${link.href}-${link.label}`}>
                      <DocNavLink link={link} />
                    </li>
                  ))}
                </ul>
              ) : null}
            </section>
          ))}
        </article>
      </div>
    </div>
  )
}
