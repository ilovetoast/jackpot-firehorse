import { useState, useEffect, useCallback, useRef } from 'react'

const FULL_COL_CONFIG = [
    { w: '17%', bottom: '-30%', slots: 1 },
    { w: '19%', bottom: '-15%', slots: 2 },
    { w: '24%', bottom: '0%',   slots: 2 },
    { w: '26%', bottom: '10%',  slots: 2 },
    { w: '20%', bottom: '18%',  slots: 1 },
]

function getLayout(count) {
    if (count === 1) {
        return [{ w: '50%', bottom: '5%', slots: 1 }]
    }
    if (count === 2) {
        return [
            { w: '40%', bottom: '-5%', slots: 1 },
            { w: '50%', bottom: '10%', slots: 1 },
        ]
    }
    if (count === 3) {
        return [
            { w: '28%', bottom: '-10%', slots: 1 },
            { w: '36%', bottom: '5%',   slots: 1 },
            { w: '30%', bottom: '15%',  slots: 1 },
        ]
    }
    if (count <= 5) {
        return [
            { w: '22%', bottom: '-20%', slots: 1 },
            { w: '26%', bottom: '-5%',  slots: 1 },
            { w: '28%', bottom: '5%',   slots: 1 },
            { w: '24%', bottom: '15%',  slots: 1 },
        ]
    }
    return FULL_COL_CONFIG
}

export default function AssetCollage({ assets = [] }) {
    const [visible, setVisible] = useState(false)
    const [mouseOffset, setMouseOffset] = useState({ x: 0, y: 0 })
    const rafRef = useRef(null)
    const lastPosRef = useRef({ x: 0, y: 0 })

    useEffect(() => {
        const t = setTimeout(() => setVisible(true), 300)
        return () => clearTimeout(t)
    }, [])

    const handleMouseMove = useCallback((e) => {
        const cx = window.innerWidth / 2
        const cy = window.innerHeight / 2
        lastPosRef.current = {
            x: (e.clientX - cx) * 0.004,
            y: (e.clientY - cy) * 0.004,
        }
        if (rafRef.current) return
        rafRef.current = requestAnimationFrame(() => {
            rafRef.current = null
            setMouseOffset(lastPosRef.current)
        })
    }, [])

    useEffect(() => {
        window.addEventListener('mousemove', handleMouseMove, { passive: true })
        return () => {
            window.removeEventListener('mousemove', handleMouseMove)
            if (rafRef.current) cancelAnimationFrame(rafRef.current)
        }
    }, [handleMouseMove])

    if (!assets.length) return null

    const thumbs = assets
        .slice(0, 8)
        .map((a) => a.final_thumbnail_url || a.thumbnail_url || a.preview_thumbnail_url)
        .filter(Boolean)

    if (thumbs.length === 0) return null

    const layout = getLayout(thumbs.length)

    let idx = 0
    const columns = layout.map((col) => {
        const imgs = []
        for (let s = 0; s < col.slots && idx < thumbs.length; s++, idx++) {
            imgs.push(thumbs[idx])
        }
        return { ...col, imgs }
    }).filter((col) => col.imgs.length > 0)

    const isFew = thumbs.length <= 2

    const parallaxStyle = {
        transform: `translate3d(${mouseOffset.x}px, ${mouseOffset.y}px, 0)`,
        transition: 'transform 0.8s cubic-bezier(0.25, 0.1, 0.25, 1)',
        willChange: 'transform',
        backfaceVisibility: 'hidden',
    }

    return (
        <div className="absolute right-0 bottom-0 h-full w-[55%] pointer-events-none hidden lg:block overflow-hidden" style={{ contain: 'layout paint' }}>
            <div
                className={`absolute bottom-0 right-0 flex gap-3 items-end ${isFew ? 'pr-12' : 'left-0 px-2'}`}
                style={parallaxStyle}
            >
                {columns.map((col, ci) => (
                    <div
                        key={ci}
                        className="flex flex-col gap-3 shrink-0"
                        style={{
                            width: col.w,
                            marginBottom: col.bottom,
                            transition: `opacity 0.8s ease ${200 + ci * 120}ms, transform 0.8s ease ${200 + ci * 120}ms`,
                            opacity: visible ? 1 : 0,
                            transform: visible ? 'translate3d(0,0,0)' : 'translate3d(0,30px,0)',
                            willChange: visible ? 'auto' : 'transform',
                            backfaceVisibility: 'hidden',
                        }}
                    >
                        {col.imgs.map((src, ii) => (
                            <div
                                key={ii}
                                className="w-full rounded-2xl overflow-hidden ring-1 ring-white/[0.06] shadow-[0_8px_30px_rgba(0,0,0,0.5)]"
                                style={{
                                    aspectRatio: isFew ? '3/4' : (ii === 0 && col.slots === 2 ? '3/4' : '4/5'),
                                    contain: 'layout paint',
                                    transform: 'translateZ(0)',
                                }}
                            >
                                <img
                                    src={src}
                                    alt=""
                                    className="w-full h-full object-cover"
                                    loading={ci < 2 ? 'eager' : 'lazy'}
                                    decoding="async"
                                    fetchPriority={ci < 2 ? 'high' : 'low'}
                                />
                            </div>
                        ))}
                    </div>
                ))}
            </div>
        </div>
    )
}
