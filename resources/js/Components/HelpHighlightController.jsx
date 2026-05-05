import { usePage } from '@inertiajs/react'
import HelpGuidedHighlightOverlay from './HelpGuidedHighlightOverlay'
import { useHelpHighlightFromUrl } from '../hooks/useHelpHighlightFromUrl'

/**
 * App-level: react to `?help=&highlight=` (optional `highlight_label=`) after Inertia navigations.
 */
export default function HelpHighlightController() {
    const { url } = usePage()
    const [session, dismiss] = useHelpHighlightFromUrl(url)

    return <HelpGuidedHighlightOverlay session={session} onDismiss={dismiss} />
}
