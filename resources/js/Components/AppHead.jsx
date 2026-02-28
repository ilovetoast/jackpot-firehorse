/**
 * AppHead — Sets document title for SPA pages with consistent format.
 *
 * Format for tenant app: {page} - {active brand} - Jackpot
 * Uses both Inertia Head and useEffect so title updates reliably (Inertia Head
 * does not replace titles already in the server-side root template).
 *
 * @param {string} title - Page name (e.g. "Assets", "Company Settings")
 * @param {string} [suffix] - Optional suffix instead of brand (e.g. "Admin" for admin pages)
 */
import { useEffect } from 'react'
import { Head, usePage } from '@inertiajs/react'

const APP_NAME = 'Jackpot'

export default function AppHead({ title, suffix }) {
    const { props } = usePage()
    const auth = props.auth ?? {}
    const activeBrand = auth.activeBrand
    const collectionOnly = props.collection_only
    const collectionBrand = props.collection_only_collection?.brand

    let fullTitle
    if (suffix) {
        // Admin or custom suffix: "Assets - Admin - Jackpot"
        fullTitle = `${title} - ${suffix} - ${APP_NAME}`
    } else if (activeBrand?.name) {
        // Tenant app with brand: "Assets - Velvet Hammer - Jackpot"
        fullTitle = `${title} - ${activeBrand.name} - ${APP_NAME}`
    } else if (collectionOnly && collectionBrand?.name) {
        // Collection-only mode: use collection's brand name
        fullTitle = `${title} - ${collectionBrand.name} - ${APP_NAME}`
    } else {
        // Companies list, no brand, etc: "Companies - Jackpot"
        fullTitle = `${title} - ${APP_NAME}`
    }

    // useEffect ensures document.title updates even if Inertia Head doesn't replace
    useEffect(() => {
        document.title = fullTitle
    }, [fullTitle])

    return <Head title={fullTitle} />
}
