import { useState, useEffect, useRef } from 'react'
import { TagIcon } from '@heroicons/react/24/outline'

export default function BrandRoleSelector({
    brands,
    selectedBrands = [],
    onChange,
    errors = {},
    required = true,
    allowSelectAll = true,
}) {
    const [brandRoles, setBrandRoles] = useState([])
    const [rolesLoading, setRolesLoading] = useState(true)

    useEffect(() => {
        let cancelled = false
        fetch('/app/api/roles/brand', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((r) => r.json())
            .then((data) => {
                if (cancelled) return
                const list = Array.isArray(data.roles) ? data.roles : []
                setBrandRoles(list)
            })
            .catch(() => {
                if (!cancelled) setBrandRoles([])
            })
            .finally(() => {
                if (!cancelled) setRolesLoading(false)
            })
        return () => {
            cancelled = true
        }
    }, [])

    const roleValues = brandRoles.map((r) => r.value)
    const defaultRole = roleValues.includes('viewer') ? 'viewer' : roleValues[0] || 'viewer'

    const normalizeRole = (role) => {
        let r = role
        if (r === 'owner') r = 'admin'
        if (r === 'member') r = 'viewer'
        if (roleValues.length && !roleValues.includes(r)) {
            return defaultRole
        }
        return r || defaultRole
    }

    const [brandAssignments, setBrandAssignments] = useState(() => {
        if (selectedBrands && selectedBrands.length > 0) {
            return selectedBrands.map((b) => {
                let role = b.role
                if (role === 'owner') role = 'admin'
                if (role === 'member') role = 'viewer'
                return {
                    brand_id: b.brand_id || b.id,
                    role: role || 'viewer',
                }
            })
        }
        return []
    })

    const isInitialMount = useRef(true)
    const onChangeRef = useRef(onChange)

    useEffect(() => {
        onChangeRef.current = onChange
    }, [onChange])

    useEffect(() => {
        if (rolesLoading || brandRoles.length === 0) {
            return
        }
        setBrandAssignments((prev) =>
            prev.map((b) => ({
                ...b,
                role: normalizeRole(b.role),
            }))
        )
    }, [rolesLoading, brandRoles])

    useEffect(() => {
        if (isInitialMount.current) {
            isInitialMount.current = false
            return
        }
        if (onChangeRef.current) {
            onChangeRef.current(brandAssignments)
        }
    }, [brandAssignments])

    const toggleBrand = (brandId) => {
        const exists = brandAssignments.find((b) => b.brand_id === brandId)

        if (exists) {
            setBrandAssignments(brandAssignments.filter((b) => b.brand_id !== brandId))
        } else {
            setBrandAssignments([
                ...brandAssignments,
                { brand_id: brandId, role: defaultRole },
            ])
        }
    }

    const updateRole = (brandId, role) => {
        setBrandAssignments(
            brandAssignments.map((b) => (b.brand_id === brandId ? { ...b, role } : b))
        )
    }

    const selectAll = () => {
        const allBrands = brands.map((b) => ({
            brand_id: b.id,
            role: defaultRole,
        }))
        setBrandAssignments(allBrands)
    }

    const selectNone = () => {
        setBrandAssignments([])
    }

    return (
        <div>
            <div className="flex items-center justify-between mb-2">
                <label className="block text-sm font-medium leading-6 text-gray-900">
                    Brand & Role Assignments {required && <span className="text-red-600">*</span>}
                </label>
                {allowSelectAll && brands && brands.length > 0 && (
                    <div className="flex gap-2">
                        <button
                            type="button"
                            onClick={selectAll}
                            className="text-xs text-indigo-600 hover:text-indigo-800 font-medium"
                        >
                            Select All
                        </button>
                        <span className="text-xs text-gray-400">|</span>
                        <button
                            type="button"
                            onClick={selectNone}
                            className="text-xs text-indigo-600 hover:text-indigo-800 font-medium"
                        >
                            Select None
                        </button>
                    </div>
                )}
            </div>

            {rolesLoading && (
                <p className="text-sm text-gray-500 py-2">Loading role options…</p>
            )}

            {!rolesLoading && brands && brands.length > 0 ? (
                <div className="space-y-3 border border-gray-200 rounded-lg p-4">
                    {brands.map((brand) => {
                        const assignment = brandAssignments.find((b) => b.brand_id === brand.id)
                        const isSelected = !!assignment

                        return (
                            <div
                                key={brand.id}
                                className="flex items-center justify-between py-2 px-3 bg-white rounded-md border border-gray-200 hover:border-indigo-300 transition-colors"
                            >
                                <label className="flex items-center flex-1 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        checked={isSelected}
                                        onChange={() => toggleBrand(brand.id)}
                                        className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                    />
                                    <span className="ml-3 flex items-center gap-2 text-sm text-gray-900">
                                        <TagIcon className="h-4 w-4 text-gray-400" />
                                        {brand.name}
                                        {brand.is_default && (
                                            <span className="ml-2 text-xs text-gray-500">(Default)</span>
                                        )}
                                    </span>
                                </label>

                                {isSelected && brandRoles.length > 0 && (
                                    <select
                                        value={assignment.role === 'owner' ? 'admin' : assignment.role}
                                        onChange={(e) => updateRole(brand.id, e.target.value)}
                                        onClick={(e) => e.stopPropagation()}
                                        className="ml-4 block rounded-md border-0 py-1.5 px-3 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600"
                                    >
                                        {brandRoles.map((r) => (
                                            <option key={r.value} value={r.value}>
                                                {r.label}
                                            </option>
                                        ))}
                                    </select>
                                )}
                            </div>
                        )
                    })}
                </div>
            ) : (
                !rolesLoading && (
                    <div className="text-sm text-gray-500 py-2">
                        No brands available. Please create a brand first.
                    </div>
                )
            )}

            {errors.brands && <p className="mt-2 text-sm text-red-600">{errors.brands}</p>}

            {required && brandAssignments.length === 0 && (
                <p className="mt-2 text-sm text-gray-500">At least one brand must be selected.</p>
            )}
        </div>
    )
}
