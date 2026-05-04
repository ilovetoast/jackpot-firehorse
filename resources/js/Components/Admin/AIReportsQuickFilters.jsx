import { REPORT_RANGE_PRESETS } from '../../utils/aiReportsFilters'

/**
 * Top-of-page quick filters: time presets, agent, model (admin AI reports).
 */
export default function AIReportsQuickFilters({
    localFilters,
    filterOptions,
    onApplyPreset,
    onAgentChange,
    onModelChange,
    activePresetId,
}) {
    return (
        <div className="mb-4 rounded-lg border border-slate-200 bg-white p-4 shadow-sm ring-1 ring-slate-900/5">
            <div className="flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-end lg:justify-between">
                <div className="min-w-0 flex-1">
                    <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Time range</p>
                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {REPORT_RANGE_PRESETS.map((p) => {
                            const active = activePresetId === p.id
                            return (
                                <button
                                    key={p.id}
                                    type="button"
                                    onClick={() => onApplyPreset(p.id)}
                                    className={`rounded-full px-3 py-1 text-xs font-semibold transition-colors ${
                                        active
                                            ? 'bg-indigo-600 text-white shadow-sm'
                                            : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                                    }`}
                                >
                                    {p.label}
                                </button>
                            )
                        })}
                    </div>
                </div>
                <div className="flex flex-wrap gap-3 sm:items-end">
                    <div>
                        <label className="block text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                            Agent
                        </label>
                        <select
                            value={localFilters.agent_id || ''}
                            onChange={(e) => onAgentChange(e.target.value)}
                            className="mt-1 block min-w-[10rem] rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        >
                            <option value="">All agents</option>
                            {filterOptions?.agents?.map((agent) => (
                                <option key={agent.value} value={agent.value}>
                                    {agent.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="block text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                            Model
                        </label>
                        <select
                            value={localFilters.model_used || ''}
                            onChange={(e) => onModelChange(e.target.value)}
                            className="mt-1 block min-w-[10rem] rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        >
                            <option value="">All models</option>
                            {filterOptions?.models?.map((model) => (
                                <option key={model.value} value={model.value}>
                                    {model.label}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>
            </div>
            <p className="mt-3 text-[11px] leading-snug text-slate-500">
                Summary and the cost-by-agent / cost-by-model tables below follow these filters. Use custom dates under
                &quot;Advanced&quot; for a specific calendar range.
            </p>
        </div>
    )
}
