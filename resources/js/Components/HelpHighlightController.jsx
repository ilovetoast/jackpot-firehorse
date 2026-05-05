import { usePage } from '@inertiajs/react'
import HelpGuidedHighlightOverlay from './HelpGuidedHighlightOverlay'
import HelpShowMeMissingNotice from './HelpShowMeMissingNotice'
import { useHelpHighlightFromUrl } from '../hooks/useHelpHighlightFromUrl'

/**
 * App-level: react to `?help=&highlight=` (optional `highlight_label=`, `highlight_fb=`) after Inertia navigations.
 */
export default function HelpHighlightController() {
    const { url } = usePage()
    const [highlightSession, dismissHighlight, missingSession, dismissMissing] = useHelpHighlightFromUrl(url)

    return (
        <>
            <HelpGuidedHighlightOverlay session={highlightSession} onDismiss={dismissHighlight} />
            <HelpShowMeMissingNotice session={missingSession} onDismiss={dismissMissing} />
        </>
    )
}
