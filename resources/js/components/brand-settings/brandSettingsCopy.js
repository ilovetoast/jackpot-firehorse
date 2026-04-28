/**
 * Short page-level copy for Brand Settings (Brand Workbench).
 * Identity vs DNA: Identity = visual source of truth; DNA = rules / intelligence layer.
 */

export const BRAND_SETTINGS_MASTHEAD = {
    title: 'Brand Settings',
    description:
        'Configure identity, in-app appearance, public gateway, Brand DNA, people, and creator workflows. Library structure lives under Manage.',
}

export const SECTION_INTRO = {
    identity: {
        title: 'Brand Identity',
        description: 'Official logos, colors, and display assets for this brand.',
        affects: 'Brand selector, previews, public pages, Studio, and generated assets.',
    },
    appearance: {
        title: 'Workspace Appearance',
        description: 'Choose how this brand looks inside Jackpot.',
        affects: 'Navigation, asset grid, workspace surfaces, and in-app previews.',
    },
    publicGateway: {
        title: 'Public Gateway',
        description: 'Control the external entry experience for clients, creators, and shared links.',
        affects: 'Gateway link, public pages, invites, and sharing behavior.',
    },
    brandDna: {
        title: 'Brand DNA',
        description: 'Define the rules, voice, positioning, and standards used by guidelines and Brand Intelligence.',
        affects: 'Brand scoring, AI suggestions, metadata review, and published guidelines.',
    },
    people: {
        title: 'People',
        description: 'Invite members and manage access for this brand.',
        affects: 'Who can view, edit, and administer this brand workspace.',
    },
    creatorProgram: {
        title: 'Creator Program',
        description: 'Assign approvers and manage creator submission workflows.',
        affects: 'Creator uploads, approvals, and the creator dashboard.',
    },
    operations: {
        title: 'Operations',
        description: 'Jump to analytics, review queues, library management, and activity for this brand.',
        affects: 'Operational pages — not brand configuration.',
    },
    /** Shown at top of Brand DNA → Standards only */
    standardsVsIdentity: {
        title: 'How Standards relates to Brand Identity',
        body:
            'Brand Identity (the Identity tab) is where you upload logos, set the official palette, and control how the app shows your brand. Brand DNA → Standards is a separate layer: it stores rules Brand Intelligence and published guidelines use—allowed fonts and colors for scoring, logo usage copy, and treatments. The logo previews on this page mirror your Identity assets; to change the files, use Identity → Brand Images.',
    },
}
