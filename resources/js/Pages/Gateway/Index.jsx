import { useState, useCallback } from 'react'
import { Head, usePage } from '@inertiajs/react'
import GatewayLayout from './GatewayLayout'
import LoginForm from './LoginForm'
import RegisterForm from './RegisterForm'
import CompanySelector from './CompanySelector'
import BrandSelector from './BrandSelector'
import InviteAccept from './InviteAccept'
import EnterTransition from './EnterTransition'
import BrandSwitchModal from './BrandSwitchModal'

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
    const { flash, theme } = usePage().props
    const [mode, setMode] = useState(initialMode || MODES.LOGIN)
    const [switchOpen, setSwitchOpen] = useState(false)

    const handleToggleMode = useCallback((newMode) => {
        setMode(newMode)
    }, [])

    if (auto_enter && mode === MODES.ENTER) {
        return (
            <>
                <Head title={theme?.name || 'Jackpot'} />
                <GatewayLayout onSwitchOpen={() => setSwitchOpen(true)}>
                    <EnterTransition />
                </GatewayLayout>

                {switchOpen && (
                    <BrandSwitchModal
                        context={context}
                        onClose={() => setSwitchOpen(false)}
                    />
                )}
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
                        onToggleRegister={() => handleToggleMode(MODES.REGISTER)}
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
                        tenant={context.tenant}
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
                        onToggleRegister={() => handleToggleMode(MODES.REGISTER)}
                    />
                )
        }
    }

    return (
        <>
            <Head title={theme?.name || 'Jackpot'} />
            <GatewayLayout onSwitchOpen={() => setSwitchOpen(true)}>
                {(flash?.error || flash_error) && (
                    <div className="mb-6 px-4 py-3 rounded-lg bg-red-500/10 border border-red-500/20 text-red-300 text-sm text-center max-w-md mx-auto">
                        {flash?.error || flash_error}
                    </div>
                )}
                {renderContent()}
            </GatewayLayout>

            {switchOpen && (
                <BrandSwitchModal
                    context={context}
                    onClose={() => setSwitchOpen(false)}
                />
            )}
        </>
    )
}
