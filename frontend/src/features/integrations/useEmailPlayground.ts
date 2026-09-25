import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useI18n } from '@/i18n'
import { getToken } from '@/lib/api'
import { showError } from '@/lib/toast'
import { DEFAULT_PLAYGROUND_BODY, SEND_PATH } from './emailTypes'

export function useEmailPlayground() {
  const { t } = useI18n()
  const [playgroundPath, setPlaygroundPath] = useState(SEND_PATH)
  const [playgroundToken, setPlaygroundToken] = useState(() => getToken() ?? '')
  const [playgroundBody, setPlaygroundBody] = useState(DEFAULT_PLAYGROUND_BODY)
  const [playgroundResult, setPlaygroundResult] = useState<string | null>(null)

  const runPlayground = useMutation({
    mutationFn: async () => {
      let body: unknown
      try {
        body = JSON.parse(playgroundBody)
      } catch {
        throw new Error(t('integrations.email.playground.invalidJson'))
      }
      const headers = new Headers({
        Accept: 'application/json',
        'Content-Type': 'application/json',
      })
      const token = playgroundToken.trim()
      if (token) headers.set('Authorization', `Bearer ${token}`)

      const response = await fetch(playgroundPath, {
        method: 'POST',
        headers,
        body: JSON.stringify(body),
      })
      const text = await response.text()
      let parsed: unknown = text
      try {
        parsed = JSON.parse(text)
      } catch {
        // keep raw
      }
      return { status: response.status, body: parsed }
    },
    onSuccess: (data) => {
      setPlaygroundResult(JSON.stringify(data, null, 2))
      if (data.status >= 400) {
        const body = data.body
        const message =
          body &&
          typeof body === 'object' &&
          'error' in body &&
          body.error &&
          typeof body.error === 'object' &&
          'message' in body.error &&
          typeof body.error.message === 'string'
            ? body.error.message
            : `HTTP ${data.status}`
        showError(message)
      }
    },
    onError: (err) => {
      const message = err instanceof Error ? err.message : t('common.requestFailed')
      setPlaygroundResult(message)
      showError(message)
    },
  })

  return {
    playgroundPath,
    setPlaygroundPath,
    playgroundToken,
    setPlaygroundToken,
    playgroundBody,
    setPlaygroundBody,
    playgroundResult,
    runPlayground,
  }
}
