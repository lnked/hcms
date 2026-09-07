import { Link } from 'react-router-dom'
import { mediaUrl } from '../api/client'
import type { Article } from '../api/types'

type Props = {
  article: Article
}

export function ArticleCard({ article }: Props) {
  const cover = mediaUrl(article.cover)

  return (
    <Link className="card" to={`/articles/${article.id}`}>
      {cover ? (
        <img className="card-cover" src={cover} alt="" loading="lazy" />
      ) : (
        <div className="card-cover placeholder">No cover</div>
      )}
      <div className="card-body">
        <h2>{article.title}</h2>
        {article.excerpt ? <p>{article.excerpt}</p> : null}
        <span className="meta">{article.status}</span>
      </div>
    </Link>
  )
}
