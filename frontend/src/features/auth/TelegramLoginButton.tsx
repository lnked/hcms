import { useEffect, useRef } from 'react'

export interface TelegramAuthPayload {
  id: number
  first_name?: string
  last_name?: string
  username?: string
  photo_url?: string
  auth_date: number
  hash: string
}

declare global {
  interface Window {
    onHcmsTelegramAuth?: (user: TelegramAuthPayload) => void
  }
}

export function TelegramLoginButton({
  botUsername,
  onAuth,
}: {
  botUsername: string
  onAuth: (user: TelegramAuthPayload) => void
}) {
  const host = useRef<HTMLDivElement>(null)
  const onAuthRef = useRef(onAuth)

  useEffect(() => {
    onAuthRef.current = onAuth
  }, [onAuth])

  useEffect(() => {
    const node = host.current
    if (!node || botUsername === '') {
      return
    }
    window.onHcmsTelegramAuth = (user) => onAuthRef.current(user)
    const script = document.createElement('script')
    script.src = 'https://telegram.org/js/telegram-widget.js?22'
    script.async = true
    script.setAttribute('data-telegram-login', botUsername)
    script.setAttribute('data-size', 'large')
    script.setAttribute('data-radius', '8')
    script.setAttribute('data-request-access', 'write')
    script.setAttribute('data-onauth', 'onHcmsTelegramAuth(user)')
    node.replaceChildren(script)
    return () => {
      delete window.onHcmsTelegramAuth
      node.replaceChildren()
    }
  }, [botUsername])

  return <div ref={host} className="flex min-h-10 justify-center" />
}
