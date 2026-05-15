import { useState, useCallback, useEffect } from 'react'
import { Head, usePage } from '@inertiajs/react'
import GatewayLayout from './GatewayLayout'
import LoginForm from './LoginForm'
import RegisterForm from './RegisterForm'
import CompanySelector from './CompanySelector'
import BrandSelector from './BrandSelector'
import InviteAccept from './InviteAccept'
import EnterTransition from './EnterTransition'

const MODES = {
    LOGIN: 'login',
    REGISTER: 'register',
    COMPANY_SELECT: 'company_select',
    BRAND_SELECT: 'brand_select',
    ENTER: 'enter',
    INVITE_ACCEPT: 'invite_accept',
    INVITE_REGISTER: 'invite_register',
    INVITE_LOGIN: 'invite_login',
}

export default function GatewayIndex({ context, mode: initialMode, invite_token, flash_error, auto_enter }) {
    const { flash, theme, signup_enabled: signupEnabledProp } = usePage().props
    const [mode, setMode] = useState(initialMode || MODES.LOGIN)
    const [pickerAmbientBrand, setPickerAmbientBrand] = useState(null)

    useEffect(() => {
        const clearsHover = [
            MODES.LOGIN,
            MODES.REGISTER,
            MODES.COMPANY_SELECT,
            MODES.INVITE_LOGIN,
            MODES.INVITE_REGISTER,
        ]
        if (clearsHover.includes(mode)) {
            setPickerAmbientBrand(null)
        }
    }, [mode])

    // The workspace pickers always use the Jackpot cinematic shell (no brand chosen yet).
    // Auth forms (login / register) adopt the brand's ambient when a brand URL was specified
    // (e.g. /gateway?brand=nebo&mode=login) — theme.mode will be 'brand' or 'tenant' in that case.
    const hasBrandOrTenantTheme = theme?.mode && theme.mode !== 'default'
    const jackpotPreBrandShell = [MODES.COMPANY_SELECT, MODES.BRAND_SELECT].includes(mode)
        || (!hasBrandOrTenantTheme && [
            MODES.LOGIN,
            MODES.REGISTER,
            MODES.INVITE_LOGIN,
            MODES.INVITE_REGISTER,
        ].includes(mode))

    const handleToggleMode = useCallback((newMode) => {
        setMode(newMode)
    }, [])

    if (auto_enter && mode === MODES.ENTER) {
        return (
            <>
                <Head title={theme?.name || 'Jackpot'} />
                <GatewayLayout ambient="theme" showHeaderLogo={false}>
                    <EnterTransition />
                </GatewayLayout>
            </>
        )
    }

    const renderContent = () => {
        switch (mode) {
            case MODES.LOGIN:
            case MODES.INVITE_LOGIN:
                return (
                    <LoginForm
                        context={context}
                        onToggleRegister={signupEnabledProp !== false ? () => handleToggleMode(MODES.REGISTER) : undefined}
                        inviteToken={mode === MODES.INVITE_LOGIN ? invite_token : null}
                    />
                )

            case MODES.REGISTER:
                return (
                    <RegisterForm
                        context={context}
                        onToggleLogin={() => handleToggleMode(MODES.LOGIN)}
                    />
                )

            case MODES.COMPANY_SELECT:
                return (
                    <CompanySelector
                        companies={context.available_companies}
                    />
                )

            case MODES.BRAND_SELECT:
                return (
                    <BrandSelector
                        brands={context.available_brands}
                        brandPickerGroups={context.brand_picker_groups}
                        tenant={context.tenant}
                        brandPickerScope={context.brand_picker_scope}
                        tenantMemberWithoutBrands={Boolean(context.tenant_member_without_brands)}
                        activeBrandId={context.brand?.id ?? null}
                        onAmbientHover={setPickerAmbientBrand}
                    />
                )

            case MODES.ENTER:
                return <EnterTransition />

            case MODES.INVITE_ACCEPT:
            case MODES.INVITE_REGISTER:
                return (
                    <InviteAccept
                        invitation={context.invitation}
                        isAuthenticated={context.is_authenticated}
                        token={invite_token}
                    />
                )

            default:
                return (
                    <LoginForm
                        context={context}
                        onToggleRegister={signupEnabledProp !== false ? () => handleToggleMode(MODES.REGISTER) : undefined}
                    />
                )
        }
    }

    return (
        <>
            <Head title={theme?.name || 'Jackpot'} />
            <GatewayLayout
                ambient={jackpotPreBrandShell ? 'jackpot-pick' : 'theme'}
                ambientHoverBrand={mode === MODES.BRAND_SELECT ? pickerAmbientBrand : null}
                layoutMode={(() => {
                    if (mode !== MODES.BRAND_SELECT) return 'default'
                    const scope  = context?.brand_picker_scope
                    const brands = context?.available_brands ?? []
                    const groups = context?.brand_picker_groups
                    if (scope !== 'all_workspaces') return 'default'
                    if (brands.length > 16 || (Array.isArray(groups) && groups.length > 5)) return 'enterprise'
                    return 'lanes'
                })()}
                showHeaderLogo={mode !== MODES.ENTER}
            >
                {(flash?.error || flash_error) && (
                    <div className="mb-6 px-4 py-3 rounded-lg bg-red-500/10 border border-red-500/20 text-red-300 text-sm text-center max-w-md mx-auto">
                        {flash?.error || flash_error}
                    </div>
                )}
                {renderContent()}
            </GatewayLayout>
        </>
    )
}
