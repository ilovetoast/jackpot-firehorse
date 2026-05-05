import { usePage } from '@inertiajs/react'
import { useHelpHighlightFromUrl } from '../hooks/useHelpHighlightFromUrl'

/**
 * App-level: react to `?help=&highlight=` after Inertia navigations.
 */
export default function HelpHighlightController() {
    const { url } = usePage()
    useHelpHighlightFromUrl(url)

    return null
}
