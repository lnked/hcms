import { useEffect, useState } from 'react'
import { DotLottieReact, type DotLottie } from '@lottiefiles/dotlottie-react'
import { cn } from '@/lib/utils'

interface AppLottieProps {
  src: string
  className?: string
  loop?: boolean
  autoplay?: boolean
}

export function AppLottie({ src, className, loop = true, autoplay = true }: AppLottieProps) {
  return (
    <AppLottiePlayer key={src} src={src} className={className} loop={loop} autoplay={autoplay} />
  )
}

function AppLottiePlayer({ src, className, loop = true, autoplay = true }: AppLottieProps) {
  const [instance, setInstance] = useState<DotLottie | null>(null)
  const [failed, setFailed] = useState(false)
  const [, setTick] = useState(0)

  useEffect(() => {
    if (!instance || instance.isLoaded) return

    const onLoad = () => setTick((n) => n + 1)
    const onError = () => setFailed(true)
    instance.addEventListener('load', onLoad)
    instance.addEventListener('loadError', onError)
    return () => {
      instance.removeEventListener('load', onLoad)
      instance.removeEventListener('loadError', onError)
    }
  }, [instance])

  const ready = Boolean(instance?.isLoaded)

  if (failed) return null

  return (
    <div className={cn(ready ? 'h-36 w-36' : 'h-0 w-0 overflow-hidden', className)} aria-hidden>
      <DotLottieReact
        src={src}
        loop={loop}
        autoplay={autoplay}
        className="h-full w-full"
        dotLottieRefCallback={setInstance}
      />
    </div>
  )
}
