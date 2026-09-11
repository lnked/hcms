import { clsx } from 'clsx'
import { ExternalLink } from 'lucide-react'
import { Link, Navigate, NavLink, useParams } from 'react-router-dom'
import { CodeBlock } from '@/components/CodeBlock'
import { useI18n } from '@/i18n'
import { DEFAULT_CHAPTER, getChapter, getChapters, isChapterId, type DocLink } from './chapters'
import styles from './DocsPage.module.css'

function DocNavLink({ link }: { link: DocLink }) {
  if (link.external) {
    return (
      <a
        href={link.href}
        target="_blank"
        rel="noopener noreferrer"
        className={clsx(styles.docLinkExternal)}
      >
        {link.label}
        <ExternalLink className={clsx(styles.externalIcon)} />
      </a>
    )
  }

  return (
    <Link to={link.href} className={clsx(styles.docLink)}>
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
    <div className={clsx(styles.root)}>
      <div className={clsx(styles.header)}>
        <h1 className={clsx(styles.title)}>{t('docs.title')}</h1>
        <p className={clsx(styles.subtitle)}>{t('docs.subtitle')}</p>
      </div>

      <div className={clsx(styles.layout)}>
        <nav aria-label={t('docs.toc')} className={clsx(styles.nav)}>
          <p className={clsx(styles.tocLabel)}>{t('docs.toc')}</p>
          <ul className={clsx(styles.tocList)}>
            {chapters.map((item) => (
              <li key={item.id} className={clsx(styles.tocItem)}>
                <NavLink
                  to={`/docs/${item.id}`}
                  className={({ isActive }) =>
                    clsx(styles.tocLink, isActive && styles.tocLinkActive)
                  }
                >
                  {item.title}
                </NavLink>
              </li>
            ))}
          </ul>
        </nav>

        <article className={clsx(styles.article)}>
          <h2 className={clsx(styles.chapterTitle)}>{chapter.title}</h2>
          {chapter.sections.map((section, index) => (
            <section key={`${chapter.id}-${index}`} className={clsx(styles.section)}>
              {section.heading ? (
                <h3 className={clsx(styles.sectionHeading)}>{section.heading}</h3>
              ) : null}
              {section.paragraphs?.map((paragraph) => (
                <p key={paragraph} className={clsx(styles.paragraph)}>
                  {paragraph}
                </p>
              ))}
              {section.samples?.map((sample) => (
                <CodeBlock
                  key={`${sample.language}-${sample.label ?? ''}-${sample.code.slice(0, 40)}`}
                  code={sample.code}
                  label={sample.label}
                  language={sample.language}
                />
              ))}
              {section.links && section.links.length > 0 ? (
                <ul className={clsx(styles.linkList)}>
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
