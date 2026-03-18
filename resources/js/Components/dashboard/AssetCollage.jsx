import { useState, useEffect, useCallback } from 'react'

const COL_CONFIG = [
    { w: '17%', bottom: '-30%', slots: 1 },
    { w: '19%', bottom: '-15%', slots: 2 },
    { w: '24%', bottom: '0%',   slots: 2 },
    { w: '26%', bottom: '10%',  slots: 2 },
    { w: '20%', bottom: '18%',  slots: 1 },
]

export default function AssetCollage({ assets = [] }) {
    const [visible, setVisible] = useState(false)
    const [mouseOffset, setMouseOffset] = useState({ x: 0, y: 0 })

    useEffect(() => {
        const t = setTimeout(() => setVisible(true), 300)
        return () => clearTimeout(t)
    }, [])

    const handleMouseMove = useCallback((e) => {
        const cx = window.innerWidth / 2
        const cy = window.innerHeight / 2
        setMouseOffset({
            x: (e.clientX - cx) * 0.004,
            y: (e.clientY - cy) * 0.004,
        })
    }, [])

    useEffect(() => {
        window.addEventListener('mousemove', handleMouseMove, { passive: true })
        return () => window.removeEventListener('mousemove', handleMouseMove)
    }, [handleMouseMove])

    if (!assets.length) return null

    const thumbs = assets
        .slice(0, 8)
        .map((a) => a.final_thumbnail_url || a.thumbnail_url || a.preview_thumbnail_url)
        .filter(Boolean)

    if (thumbs.length === 0) return null

    let idx = 0
    const columns = COL_CONFIG.map((col) => {
        const imgs = []
        for (let s = 0; s < col.slots && idx < thumbs.length; s++, idx++) {
            imgs.push(thumbs[idx])
        }
        return { ...col, imgs }
    }).filter((col) => col.imgs.length > 0)

    return (
        <div className="absolute right-0 bottom-0 h-full w-[55%] pointer-events-none hidden lg:block overflow-hidden">
            <div
                className="absolute bottom-0 left-0 right-0 flex gap-3 items-end px-2"
                style={{
                    transform: `translate(${mouseOffset.x}px, ${mouseOffset.y}px)`,
                    transition: 'transform 0.8s cubic-bezier(0.25, 0.1, 0.25, 1)',
                }}
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
                            transform: visible ? 'translateY(0)' : 'translateY(30px)',
                        }}
                    >
                        {col.imgs.map((src, ii) => (
                            <div
                                key={ii}
                                className="w-full rounded-2xl overflow-hidden ring-1 ring-white/[0.06] shadow-[0_8px_30px_rgba(0,0,0,0.5)]"
                                style={{ aspectRatio: ii === 0 && col.slots === 2 ? '3/4' : '4/5' }}
                            >
                                <img
                                    src={src}
                                    alt=""
                                    className="w-full h-full object-cover"
                                    loading={ci < 2 ? 'eager' : 'lazy'}
                                />
                            </div>
                        ))}
                    </div>
                ))}
            </div>
        </div>
    )
}
