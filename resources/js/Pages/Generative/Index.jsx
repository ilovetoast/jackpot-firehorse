import { usePage } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppHead from '../../Components/AppHead'
import {
    SparklesIcon,
} from '@heroicons/react/24/outline'

export default function GenerativeIndex({ generative_items = [] }) {
    const { auth } = usePage().props

    return (
        <div className="h-screen flex flex-col overflow-hidden">
            <AppHead title="Generative" />
            <AppNav brand={auth.activeBrand} tenant={null} />
            
            <div className="flex flex-1 overflow-hidden" style={{ height: 'calc(100vh - 5rem)' }}>
                {/* Main Content - Full Width */}
                <div className="flex-1 overflow-hidden bg-gray-50 h-full relative">
                    <div className="h-full overflow-y-auto">
                        <div className="py-6 px-4 sm:px-6 lg:px-8">
                            {/* Temporary placeholder content */}
                            <div className="max-w-2xl mx-auto py-16 px-6 text-center">
                                <div className="mb-8">
                                    <SparklesIcon className="mx-auto h-16 w-16 text-gray-300" />
                                </div>
                                <h2 className="text-xl font-bold tracking-tight text-gray-900 sm:text-2xl">
                                    Generative - TMP
                                </h2>
                                <p className="mt-4 text-base leading-7 text-gray-600">
                                    Temporary placeholder page for Generative AI feature. This page will be developed in a future phase.
                                </p>
                                <div className="mt-8">
                                    <p className="text-sm text-gray-500">
                                        Coming Soon: AI-powered content generation and creative tools
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    )
}