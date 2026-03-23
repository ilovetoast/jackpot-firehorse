import type { BrandContext } from './documentModel'

export async function fetchEditorBrandContext(): Promise<BrandContext | null> {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
    const res = await fetch('/app/api/editor/brand-context', {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf ?? '' },
        credentials: 'same-origin',
    })
    if (!res.ok) {
        return null
    }
    const data = (await res.json()) as { brand_context?: BrandContext | null }
    return data.brand_context ?? null
}
