import './bootstrap'
import '../css/app.css'

import { createInertiaApp } from '@inertiajs/react'
import { createRoot } from 'react-dom/client'
import BrandThemeProvider from './Components/BrandThemeProvider'
import FlashMessage from './Components/FlashMessage'
import AssetProcessingTray from './Components/AssetProcessingTray'

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
        
        // Wrap the page component with FlashMessage and AssetProcessingTray
        // These components need access to Inertia context via usePage() hook
        // AssetProcessingTray handles its own visibility (only shows if processing assets exist)
        return (props) => (
            <>
                <PageComponent {...props} />
                <FlashMessage />
                <AssetProcessingTray />
            </>
        )
    },
    setup({ el, App, props }) {
        const root = createRoot(el)
        root.render(
            <BrandThemeProvider initialPage={props.initialPage}>
                <App {...props} />
            </BrandThemeProvider>
        )
    },
})
