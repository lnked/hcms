import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { Layout } from './components/Layout'
import { ArticlePage } from './pages/ArticlePage'
import { CategoriesPage } from './pages/CategoriesPage'
import { EventsPage } from './pages/EventsPage'
import { HomePage } from './pages/HomePage'
import { PlaygroundPage } from './pages/PlaygroundPage'

export function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route element={<Layout />}>
          <Route index element={<HomePage />} />
          <Route path="articles/:id" element={<ArticlePage />} />
          <Route path="categories" element={<CategoriesPage />} />
          <Route path="events" element={<EventsPage />} />
          <Route path="playground" element={<PlaygroundPage />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Routes>
    </BrowserRouter>
  )
}
