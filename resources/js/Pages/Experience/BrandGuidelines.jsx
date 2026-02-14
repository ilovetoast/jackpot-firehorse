import { useState } from 'react'
import CinematicLayout from '../../Layouts/CinematicLayout'

const MOCK_IMAGES = [
    { id: 1, title: 'Outdoor Lifestyle', meta: '16:9 • Dark mood' },
    { id: 2, title: 'Product Detail', meta: '16:9 • Close-up' },
    { id: 3, title: 'Nature & Craft', meta: '16:9 • Natural light' },
    { id: 4, title: 'Silhouette', meta: '16:9 • Low light' },
]

export default function BrandGuidelines({ brand }) {
    const [hoveredCard, setHoveredCard] = useState(null)

    return (
        <CinematicLayout brand={brand}>
            <div className="min-h-screen">
                {/* Hero */}
                <section className="relative min-h-screen flex flex-col items-center justify-center">
                    <div
                        className="absolute inset-0 bg-cover bg-center"
                        style={{ backgroundImage: `url(${brand.heroImage})` }}
                    />
                    <div
                        className="absolute inset-0"
                        style={{
                            background:
                                'linear-gradient(to bottom, transparent 0%, rgba(11,11,13,0.4) 50%, #0B0B0D 100%)',
                        }}
                    />
                    <div className="relative z-10 flex flex-col items-center text-center px-6">
                        <img
                            src={brand.logoUrl}
                            alt={brand.name}
                            className="h-20 md:h-28 w-auto object-contain opacity-95 mb-4"
                        />
                        <p className="text-lg text-white/65">{brand.tagline}</p>
                        <div className="mt-16 flex flex-col items-center gap-2 text-white/50 text-xs uppercase tracking-widest">
                            <span>Scroll</span>
                            <span className="animate-bounce">↓</span>
                        </div>
                    </div>
                </section>

                {/* Philosophy */}
                <section className="py-24 px-6 max-w-6xl mx-auto">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-16 items-start">
                        <h2 className="text-[56px] font-light leading-tight text-white">
                            Philosophy
                        </h2>
                        <div className="space-y-4 text-lg text-white/65">
                            <p>
                                Crafted precision defines everything we do. Every
                                detail, every decision, every moment is intentional.
                            </p>
                            <p>
                                Our brand speaks through restraint and excellence.
                                We don&apos;t shout—we resonate.
                            </p>
                        </div>
                    </div>
                </section>

                {/* The Mark */}
                <section className="py-24 px-6 bg-white/[0.02]">
                    <h2 className="text-[56px] font-light text-white text-center mb-16">
                        The Mark
                    </h2>
                    <div className="flex flex-col items-center gap-8">
                        <img
                            src={brand.logoUrl}
                            alt={`${brand.name} logo`}
                            className="h-24 md:h-32 w-auto object-contain opacity-95"
                        />
                        <div className="flex gap-4">
                            <button
                                type="button"
                                className="px-6 py-3 border border-white/[0.08] bg-white/[0.04] text-white/80 text-sm uppercase tracking-widest rounded-lg hover:bg-white/[0.08] transition-colors duration-500"
                            >
                                SVG
                            </button>
                            <button
                                type="button"
                                className="px-6 py-3 border border-white/[0.08] bg-white/[0.04] text-white/80 text-sm uppercase tracking-widest rounded-lg hover:bg-white/[0.08] transition-colors duration-500"
                            >
                                PNG
                            </button>
                        </div>
                    </div>
                </section>

                {/* The Palette */}
                <section className="py-24 px-6">
                    <h2 className="text-[56px] font-light text-white text-center mb-16">
                        The Palette
                    </h2>
                    <div className="max-w-4xl mx-auto space-y-6">
                        <div
                            className="h-48 md:h-64 rounded-lg flex items-center justify-center"
                            style={{ backgroundColor: brand.primaryColor }}
                        >
                            <span className="text-3xl md:text-5xl font-mono text-white/90 tracking-wider">
                                {brand.primaryColor}
                            </span>
                        </div>
                        <div
                            className="h-32 md:h-40 rounded-lg flex items-center justify-center"
                            style={{ backgroundColor: brand.secondaryColor }}
                        >
                            <span className="text-2xl md:text-4xl font-mono text-white/90 tracking-wider">
                                {brand.secondaryColor}
                            </span>
                        </div>
                    </div>
                </section>

                {/* Image Language */}
                <section className="py-24 px-6">
                    <h2 className="text-[56px] font-light text-white text-center mb-16">
                        Image Language
                    </h2>
                    <div className="overflow-x-auto pb-8 -mx-6 px-6">
                        <div className="flex gap-6 min-w-max">
                            {MOCK_IMAGES.map((img) => (
                                <div
                                    key={img.id}
                                    className="group w-80 flex-shrink-0"
                                    onMouseEnter={() => setHoveredCard(img.id)}
                                    onMouseLeave={() => setHoveredCard(null)}
                                >
                                    <div
                                        className="relative aspect-video rounded-lg bg-white/[0.04] border border-white/[0.08] overflow-hidden transition-all duration-500 ease-in-out hover:border-[var(--brand-primary)] hover:translate-y-[-2px]"
                                        style={{
                                            background: `linear-gradient(135deg, ${brand.primaryColor}40 0%, #0B0B0D 100%)`,
                                        }}
                                    >
                                        <div className="h-full flex items-center justify-center text-white/30 text-sm">
                                            {img.title}
                                        </div>
                                        <div
                                            className={`absolute bottom-0 left-0 right-0 p-4 bg-black/60 text-white text-sm transition-opacity duration-500 ${
                                                hoveredCard === img.id
                                                    ? 'opacity-100'
                                                    : 'opacity-0'
                                            }`}
                                        >
                                            <div className="font-medium">
                                                {img.title}
                                            </div>
                                            <div className="text-white/65 text-xs uppercase tracking-widest mt-1">
                                                {img.meta}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                </section>
            </div>
        </CinematicLayout>
    )
}
