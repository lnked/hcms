import { DotLottieReact } from '@lottiefiles/dotlottie-react'
import { cn } from '@/lib/utils'

interface AppLottieProps {
  src: string
  className?: string
  loop?: boolean
  autoplay?: boolean
}

export function AppLottie({ src, className, loop = true, autoplay = true }: AppLottieProps) {
  return (
    <div className={cn('h-36 w-36', className)} aria-hidden>
      <DotLottieReact src={src} loop={loop} autoplay={autoplay} className="h-full w-full" />
    </div>
  )
}
