import './bootstrap'
import '../css/app.css'

import { createInertiaApp } from '@inertiajs/react'
import { createRoot } from 'react-dom/client'
import BrandThemeProvider from './Components/BrandThemeProvider'
import FlashMessage from './Components/FlashMessage'
import AssetProcessingTray from './Components/AssetProcessingTray'
import DownloadBucketBarGlobal from './Components/DownloadBucketBarGlobal'
import { BucketProvider } from './contexts/BucketContext'

const pages = import.meta.glob('./Pages/**/*.jsx', { eager: false })

createInertiaApp({
    resolve: async name => {
        const path = `./Pages/${name}.jsx`
        const component = pages[path]
        if (!component) {
            throw new Error(`Page not found: ${name}`)
        }
        
        // Load the page component
        const pageModule = await component()
        const PageComponent = pageModule.default || pageModule
        
        // Standalone cinematic experience: no global UI (FlashMessage, tray, download bar)
        const isExperience = name.startsWith('Experience/')
        if (isExperience) {
            return (props) => <PageComponent {...props} />
        }
        
        // Wrap the page component with FlashMessage, AssetProcessingTray, and app-level download bucket bar.
        // DownloadBucketBarGlobal uses BucketContext so state is shared; rendering it here keeps it in the
        // same DOM tree as the page so it's visible (fixed bottom bar). Bucket state lives in BucketProvider
        // so the bar shows the correct count without refetch on category change.
        return (props) => (
            <>
                <PageComponent {...props} />
                <FlashMessage />
                <AssetProcessingTray />
                <DownloadBucketBarGlobal />
            </>
        )
    },
    setup({ el, App, props }) {
        const root = createRoot(el)
        root.render(
            <BrandThemeProvider initialPage={props.initialPage}>
                <BucketProvider>
                    <App {...props} />
                </BucketProvider>
            </BrandThemeProvider>
        )
    },
})
