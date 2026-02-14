import { useState, useEffect, useCallback } from 'react'
import { featureFlags } from '../../config/featureFlags'
import { getBrandBySlug } from '../../brand/mockBrands'
import BrandSelect from './BrandSelect'
import Splash from './Splash'
import Login from './Login'
import WorkspaceSelect from './WorkspaceSelect'
import BrandGuidelines from './BrandGuidelines'

const FLOW = {
    BRAND_SELECT: 'brand_select',
    SPLASH: 'splash',
    LOGIN: 'login',
    WORKSPACE_SELECT: 'workspace_select',
    BRAND_GUIDELINES: 'brand_guidelines',
    DAM_PLACEHOLDER: 'dam_placeholder',
}

function ExperienceFlow() {
    const [brand, setBrand] = useState(null)
    const [flow, setFlow] = useState(FLOW.BRAND_SELECT)

    const getBrandFromUrl = useCallback(() => {
        const params = new URLSearchParams(window.location.search)
        const slug = params.get('brand')
        return slug ? getBrandBySlug(slug) : null
    }, [])

    useEffect(() => {
        const b = getBrandFromUrl()
        if (b) {
            setBrand(b)
            setFlow(FLOW.SPLASH)
        } else {
            setBrand(null)
            setFlow(FLOW.BRAND_SELECT)
        }
    }, [getBrandFromUrl])

    useEffect(() => {
        const handlePopState = () => {
            const b = getBrandFromUrl()
            setBrand(b || null)
            setFlow(b ? FLOW.SPLASH : FLOW.BRAND_SELECT)
        }
        window.addEventListener('popstate', handlePopState)
        return () => window.removeEventListener('popstate', handlePopState)
    }, [getBrandFromUrl])

    const handleBrandSelect = useCallback((selectedBrand) => {
        setBrand(selectedBrand)
        setFlow(FLOW.SPLASH)
    }, [])

    const handleSplashComplete = useCallback(() => {
        setFlow(FLOW.LOGIN)
    }, [])

    const handleLoginEnter = useCallback(() => {
        setFlow(FLOW.WORKSPACE_SELECT)
    }, [])

    const handleSelectBrandGuidelines = useCallback(() => {
        setFlow(FLOW.BRAND_GUIDELINES)
    }, [])

    const handleSelectDAM = useCallback(() => {
        setFlow(FLOW.DAM_PLACEHOLDER)
    }, [])

    if (flow === FLOW.BRAND_SELECT) {
        return <BrandSelect onSelect={handleBrandSelect} />
    }

    if (flow === FLOW.SPLASH) {
        return <Splash onComplete={handleSplashComplete} />
    }

    if (flow === FLOW.LOGIN && brand) {
        return <Login brand={brand} onEnter={handleLoginEnter} />
    }

    if (flow === FLOW.WORKSPACE_SELECT && brand) {
        return (
            <WorkspaceSelect
                brand={brand}
                onSelectBrandGuidelines={handleSelectBrandGuidelines}
                onSelectDAM={handleSelectDAM}
            />
        )
    }

    if (flow === FLOW.BRAND_GUIDELINES && brand) {
        return <BrandGuidelines brand={brand} />
    }

    if (flow === FLOW.DAM_PLACEHOLDER && brand) {
        return (
            <div className="min-h-screen bg-[#0B0B0D] text-white flex flex-col items-center justify-center px-6">
                <h1 className="text-[56px] md:text-[96px] font-light tracking-tight text-white/95 mb-4">
                    Digital Asset Management
                </h1>
                <p className="text-lg text-white/65">
                    Organize. Govern. Distribute.
                </p>
                <p className="mt-8 text-sm text-white/40 uppercase tracking-widest">
                    Placeholder — DAM not connected
                </p>
            </div>
        )
    }

    return null
}

function FeatureDisabled() {
    return (
        <div className="min-h-screen bg-[#0B0B0D] text-white flex flex-col items-center justify-center px-6">
            <h1 className="text-2xl font-light text-white/95 mb-2">
                Feature Disabled
            </h1>
            <p className="text-white/65">
                The cinematic experience is currently unavailable.
            </p>
        </div>
    )
}

export default function ExperienceIndex() {
    if (!featureFlags.cinematicExperience) {
        return <FeatureDisabled />
    }
    return <ExperienceFlow />
}
