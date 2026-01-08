import { useState, useEffect } from 'react'
import { TagIcon } from '@heroicons/react/24/outline'

export default function BrandRoleSelector({ 
    brands, 
    selectedBrands = [], 
    onChange, 
    errors = {},
    required = true,
    allowSelectAll = true,
}) {
    const [brandAssignments, setBrandAssignments] = useState(() => {
        // Initialize with selectedBrands if provided
        if (selectedBrands && selectedBrands.length > 0) {
            return selectedBrands.map(b => ({
                brand_id: b.brand_id || b.id,
                role: b.role || 'member',
            }))
        }
        return []
    })

    useEffect(() => {
        // Notify parent of changes
        if (onChange) {
            onChange(brandAssignments)
        }
    }, [brandAssignments, onChange])

    const toggleBrand = (brandId) => {
        const exists = brandAssignments.find(b => b.brand_id === brandId)
        
        if (exists) {
            // Remove brand
            setBrandAssignments(brandAssignments.filter(b => b.brand_id !== brandId))
        } else {
            // Add brand with default role 'member'
            setBrandAssignments([
                ...brandAssignments,
                { brand_id: brandId, role: 'member' },
            ])
        }
    }

    const updateRole = (brandId, role) => {
        setBrandAssignments(brandAssignments.map(b => 
            b.brand_id === brandId ? { ...b, role } : b
        ))
    }

    const selectAll = () => {
        const allBrands = brands.map(b => ({
            brand_id: b.id,
            role: 'member',
        }))
        setBrandAssignments(allBrands)
    }

    const selectNone = () => {
        setBrandAssignments([])
    }

    const isValidRole = (role) => {
        return ['member', 'admin', 'brand_manager', 'owner'].includes(role)
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

            {brands && brands.length > 0 ? (
                <div className="space-y-3 border border-gray-200 rounded-lg p-4">
                    {brands.map((brand) => {
                        const assignment = brandAssignments.find(b => b.brand_id === brand.id)
                        const isSelected = !!assignment

                        return (
                            <div key={brand.id} className="flex items-center justify-between py-2 px-3 bg-white rounded-md border border-gray-200 hover:border-indigo-300 transition-colors">
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

                                {isSelected && (
                                    <select
                                        value={assignment.role}
                                        onChange={(e) => updateRole(brand.id, e.target.value)}
                                        onClick={(e) => e.stopPropagation()}
                                        className="ml-4 block rounded-md border-0 py-1.5 px-3 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600"
                                    >
                                        <option value="member">Member</option>
                                        <option value="admin">Admin</option>
                                        <option value="brand_manager">Brand Manager</option>
                                        <option value="owner">Owner</option>
                                    </select>
                                )}
                            </div>
                        )
                    })}
                </div>
            ) : (
                <div className="text-sm text-gray-500 py-2">
                    No brands available. Please create a brand first.
                </div>
            )}

            {errors.brands && (
                <p className="mt-2 text-sm text-red-600">{errors.brands}</p>
            )}

            {required && brandAssignments.length === 0 && (
                <p className="mt-2 text-sm text-gray-500">
                    At least one brand must be selected.
                </p>
            )}
        </div>
    )
}
