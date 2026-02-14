export type Brand = {
    id: string
    name: string
    slug: string
    tagline: string
    primaryColor: string
    secondaryColor: string
    logoUrl: string
    heroImage: string
}

export const mockBrands: Brand[] = [
    {
        id: 'stcroix',
        name: 'St. Croix',
        slug: 'st-croix',
        tagline: 'Crafted Precision.',
        primaryColor: '#0A1F44',
        secondaryColor: '#B4975A',
        logoUrl: '/mock/stcroix-logo.svg',
        heroImage: '/mock/stcroix-dark-hero.svg',
    },
]

export function getBrandBySlug(slug: string): Brand | undefined {
    return mockBrands.find((b) => b.slug === slug)
}
