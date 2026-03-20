/**
 * Extract title + message from Laravel's errors/*.blade.php HTML (403, etc.)
 */
export function parsePermissionDeniedHtml(html) {
    if (!html || typeof html !== 'string' || !html.includes('<')) {
        return {
            title: 'Access denied',
            message: 'You do not have permission to perform this action.',
        }
    }
    try {
        const doc = new DOMParser().parseFromString(html, 'text/html')
        const h1 = doc.querySelector('main h1') || doc.querySelector('h1')
        const p =
            doc.querySelector('main p.text-lg') ||
            doc.querySelector('main p.leading-8') ||
            doc.querySelector('main p.mt-4') ||
            doc.querySelector('main p')
        return {
            title: h1?.textContent?.trim() || 'Access denied',
            message:
                p?.textContent?.trim() || 'You do not have permission to perform this action.',
        }
    } catch {
        return {
            title: 'Access denied',
            message: 'You do not have permission to perform this action.',
        }
    }
}
