/**
 * Layered midnight-navy + brand spotlight backgrounds for public share pages
 * (Velvet-style radial glow, vignette to black).
 */
export function publicShareCinemaLayers(primaryHex, accentHex) {
    const primary = primaryHex || '#2563eb'
    const accent = accentHex || primary
    const cinemaBase = '#020308'
    const cinemaMid = '#0a1020'
    const primaryGlowStrong = `${primary}38`
    const primaryGlowSoft = `${primary}18`
    const accentGlowSoft = `${accent}12`

    const noPhoto = [
        `radial-gradient(ellipse 118% 92% at 42% -6%, ${primaryGlowStrong} 0%, transparent 54%)`,
        `radial-gradient(ellipse 90% 70% at 78% 4%, ${primaryGlowSoft} 0%, transparent 48%)`,
        `radial-gradient(ellipse 70% 55% at 12% 35%, ${accentGlowSoft} 0%, transparent 50%)`,
        `radial-gradient(ellipse 130% 85% at 50% 108%, #000000 0%, transparent 52%)`,
        `radial-gradient(ellipse 100% 70% at 50% -5%, rgba(15, 23, 42, 0.45) 0%, transparent 42%)`,
        `linear-gradient(168deg, ${cinemaMid} 0%, #060a14 32%, ${cinemaBase} 72%, #000000 100%)`,
    ].join(', ')

    const withPhotoOverlay = [
        `radial-gradient(ellipse 118% 92% at 42% -6%, ${primaryGlowStrong} 0%, transparent 54%)`,
        `radial-gradient(ellipse 130% 85% at 50% 108%, #000000 0%, transparent 55%)`,
        `linear-gradient(180deg, rgba(2, 3, 8, 0.82) 0%, rgba(5, 8, 18, 0.55) 38%, rgba(2, 3, 8, 0.92) 100%)`,
    ].join(', ')

    return { cinemaBase, noPhoto, withPhotoOverlay }
}
