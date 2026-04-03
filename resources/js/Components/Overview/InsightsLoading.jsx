export default function InsightsLoading() {
    return (
        <div className="relative rounded-2xl bg-white/5 border border-white/10 p-6 overflow-hidden">
            <div className="absolute inset-0 animate-pulse bg-gradient-to-r from-orange-500/10 via-transparent to-orange-500/10" />

            <div className="relative">
                <div className="w-2 h-2 bg-orange-400 rounded-full animate-bounce mb-4" />

                <div className="text-white font-medium">Building insights...</div>

                <div className="text-white/60 text-sm mt-1">
                    Analyzing your assets and brand patterns
                </div>
            </div>
        </div>
    )
}
