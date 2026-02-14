import { useState, useEffect } from 'react'
import CinematicLayout from '../../Layouts/CinematicLayout'

export default function Splash({ onComplete }) {
    const [progress, setProgress] = useState(0)

    useEffect(() => {
        const start = Date.now()
        const duration = 1800

        const tick = () => {
            const elapsed = Date.now() - start
            const p = Math.min((elapsed / duration) * 100, 100)
            setProgress(p)
            if (p < 100) {
                requestAnimationFrame(tick)
            } else {
                onComplete?.()
            }
        }
        requestAnimationFrame(tick)
    }, [onComplete])

    return (
        <CinematicLayout>
            <div className="min-h-screen flex flex-col items-center justify-center">
                <h1
                    className="text-[56px] md:text-[96px] font-light tracking-tight text-white opacity-0 animate-fade-in"
                    style={{ animationDuration: '600ms', animationFillMode: 'forwards' }}
                >
                    JACKPOT
                </h1>
                <p
                    className="text-lg text-white/65 mt-4 opacity-0 animate-fade-in"
                    style={{
                        animationDelay: '200ms',
                        animationDuration: '600ms',
                        animationFillMode: 'forwards',
                    }}
                >
                    Brand Operating System
                </p>
                <div className="mt-16 w-64 h-0.5 bg-white/20 overflow-hidden rounded-full">
                    <div
                        className="h-full bg-white/80 transition-all duration-300 ease-in-out"
                        style={{ width: `${progress}%` }}
                    />
                </div>
            </div>
        </CinematicLayout>
    )
}
