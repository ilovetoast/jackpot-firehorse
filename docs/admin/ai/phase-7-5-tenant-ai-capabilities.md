# Phase 7.5: Tenant AI Capabilities (Design Specification)

## Overview

This document defines the intended scope, boundaries, and non-goals of future tenant-facing AI features. **This is a design-only specification and does not include implementation details.**

**Critical Status: Tenant-facing AI features are NOT implemented yet.**

This design phase exists before implementation to establish clear boundaries, prevent scope creep, and ensure that any future tenant AI features align with system architecture, safety principles, and business requirements. This document serves as a reference and guardrail for future phases.

### Current State

As of Phase 7, the system includes:

- **Support ticketing system**: Full tenant and internal ticket management
- **SLA engine**: Automated SLA tracking and breach detection
- **Internal engineering tickets**: Technical issue tracking with diagnostic context
- **AI Foundations (Phase 6)**: Core AI infrastructure with provider abstraction, agent runs, and cost tracking
- **AI Automation & Triggers (Phase 7)**: System-level automation for support ticket operations

All AI capabilities implemented thus far are **system-level only** and operate behind the scenes to assist staff operations. No tenant-facing AI features exist.

### Future State

Tenant-facing AI features will enable tenants to leverage AI capabilities within their own workflows, subject to strict safety constraints, permission gating, and cost controls. These features will be built on the existing AI infrastructure while maintaining clear separation between system AI and tenant AI.

## Guiding Principles

The following principles govern all future tenant AI features:

### 1. Domain Model Attachment

AI capabilities attach to existing domain models (assets, brands, categories, tickets) rather than operating as free-form chat interfaces. AI suggestions and actions are contextual to the specific resource being worked with.

Examples:
- Asset tagging suggestions appear in the asset detail view
- Brand guideline checks occur when uploading brand assets
- Creative generation produces variations of existing assets

### 2. Suggest Before Acting

AI always suggests actions before performing them. No AI action automatically modifies tenant data without explicit human confirmation.

Examples:
- AI suggests tags for an asset; user confirms or rejects
- AI suggests deduplication matches; user confirms merge
- AI generates creative variations; user selects which to keep

### 3. Human Confirmation Required

All impactful actions require explicit human confirmation. This includes:
- Asset modifications (tagging, metadata updates, deletions)
- Brand asset generation or modification
- Deduplication merges
- Category suggestions

Confirmation is never assumed or inferred from inaction.

### 4. Tenant AI vs. System AI Separation

Tenant AI and system AI are distinct domains with different purposes, permissions, and cost attribution:

- **System AI**: Operates at the platform level, assists staff operations, attributed to system costs
- **Tenant AI**: Operates at the tenant level, assists tenant workflows, attributed to tenant usage

This separation ensures:
- Clear cost attribution and billing boundaries
- Appropriate permission enforcement
- Audit trail clarity
- Safety boundary enforcement

### 5. Observable, Auditable, and Gated

All tenant AI features must be:
- **Observable**: Usage and results visible to tenants via AI Dashboard
- **Auditable**: All actions logged with full context for compliance
- **Gated**: Subject to subscription plan limits, usage quotas, and permission checks

## Future Tenant AI Capability Categories

The following categories represent conceptual groupings of future tenant AI capabilities. These are design concepts only; no implementation exists yet.

### Asset Intelligence

AI capabilities that enhance asset management workflows:

- **Tagging Suggestions**: AI analyzes asset content (images, documents, videos) and suggests relevant tags, categories, and metadata
- **Metadata Enrichment**: AI extracts and suggests metadata fields (EXIF data, document properties, content descriptions)
- **Deduplication Detection**: AI identifies potential duplicate assets based on content similarity, not just filename matching
- **Content Analysis**: AI analyzes asset content to provide insights (image composition, document structure, video scenes)
- **Search Enhancement**: AI improves asset search by understanding content semantics beyond keyword matching

**Domain Attachment**: All capabilities operate within the asset detail view and asset management workflows.

**Confirmation Required**: Tagging suggestions, metadata updates, and deduplication merges require explicit user confirmation.

### Creative Generation

AI capabilities that generate new creative content for tenants:

- **Image Variations**: Generate variations of existing brand assets with style, composition, or color adjustments
- **Copy Generation**: Generate marketing copy, product descriptions, or brand messaging based on brand guidelines
- **Layout Suggestions**: Suggest layout variations for existing assets or brand templates
- **Style Transfer**: Apply brand style guidelines to user-provided images or templates

**Domain Attachment**: All capabilities operate within brand asset workflows and brand guidelines context.

**Confirmation Required**: All generated content requires explicit user approval before being saved as assets.

**Non-Goals**: No autonomous generation without user request; no generation that bypasses plan limits or approval workflows.

### Brand Intelligence

AI capabilities that enforce and enhance brand consistency:

- **Guideline Compliance Checks**: AI analyzes uploaded assets against brand guidelines (colors, fonts, logos, spacing) and flags violations
- **Brand Consistency Scoring**: AI scores assets for brand consistency and provides improvement suggestions
- **Guideline Summarization**: AI summarizes brand guidelines and highlights key rules for quick reference
- **Asset-to-Brand Matching**: AI suggests which brand an asset should belong to based on visual/design analysis

**Domain Attachment**: All capabilities operate within brand management workflows and brand asset upload workflows.

**Confirmation Required**: Guideline violations are flagged as warnings; suggestions require explicit user confirmation.

**Non-Goals**: No automatic rejection of assets; no silent modification of assets to "fix" violations.

### Reporting & Analysis

AI capabilities that provide insights and summaries:

- **Usage Summaries**: AI generates summaries of asset usage, brand activity, and tenant workflows
- **Trend Analysis**: AI identifies trends in asset creation, brand usage, and content patterns
- **Performance Insights**: AI provides insights on asset performance (if analytics are integrated)
- **Workflow Recommendations**: AI suggests workflow improvements based on tenant usage patterns

**Domain Attachment**: All capabilities operate within reporting dashboards and analytics views.

**Confirmation Required**: Summary generation is automatic; actionable recommendations require user confirmation.

**Non-Goals**: No automatic workflow changes; no recommendations that bypass approval processes.

## Dependencies (Explicit)

The following systems must exist and be fully operational before tenant AI features can be implemented:

### Asset System

- **Asset models**: Complete asset data model with metadata, tagging, versioning, and relationships
- **Asset storage**: S3-based storage with signed URLs, proper tenant/brand isolation, and access controls
- **Asset metadata**: Structured metadata fields that AI can read and write to
- **Asset relationships**: Relationships between assets, brands, categories, and other domain models

**Status**: Asset models and storage infrastructure exist. Metadata structure and relationships need verification before AI integration.

### Brand System

- **Brand guidelines**: Structured brand guideline storage (colors, fonts, logos, spacing rules, usage rules)
- **Brand asset associations**: Clear association between assets and brands
- **Brand identity storage**: Brand logos, icons, color palettes, and design elements stored and accessible
- **Brand workflow integration**: Brand guidelines integrated into asset upload and management workflows

**Status**: Brand models exist. Brand guidelines structure needs definition before AI can enforce compliance.

### AI Dashboard / Control Plane (Phase 8)

- **Usage visibility**: Dashboard showing tenant AI usage, costs, quotas, and limits
- **Feature toggles**: Ability to enable/disable specific AI features per tenant or plan
- **Cost transparency**: Real-time and historical cost tracking per feature and per tenant
- **Quota management**: Quota limits, usage tracking, and alerting when approaching limits

**Status**: AI Dashboard is planned for Phase 8. This is a hard dependency for tenant AI rollout.

### AI Cost Tracking and Quotas

- **Tenant-level cost attribution**: Extend `ai_agent_runs` table or create tenant AI cost tracking
- **Quota system**: Quota limits per plan, per feature, with usage tracking
- **Real-time quota checking**: Middleware or service layer that checks quotas before executing AI operations
- **Quota alerting**: Alerts when approaching or exceeding quotas

**Status**: System-level cost tracking exists via `ai_agent_runs`. Tenant-level attribution and quota system need implementation.

### Plan and Permission Gating

- **Feature flags per plan**: Subscription plan configuration defining which AI features are available
- **Permission system integration**: Spatie permissions for tenant AI features (e.g., `assets.ai_tag`, `brands.ai_generate`)
- **Plan limit enforcement**: Middleware or service layer enforcing plan-based feature limits
- **Permission checks**: Policy-based authorization for all tenant AI operations

**Status**: Plan system exists. Permission system exists. Integration with AI features needs implementation.

## Permissions & Gating (Future)

Tenant AI features will be gated by multiple layers of control. **None of this is implemented yet.**

### Subscription Plan Gating

AI features will be available only to tenants on plans that include those features. Plan configuration will define:

- Which AI features are available (asset intelligence, creative generation, brand intelligence, reporting)
- Usage quotas per feature (e.g., 100 AI tags per month, 50 image generations per month)
- Cost limits (e.g., $10/month AI credit included, $0.01 per additional tag)

Free plans may include limited AI features with strict quotas. Higher tiers include more features with higher quotas.

**Implementation Note**: Plan gating will use existing `PlanService` infrastructure with new feature flags and quota limits.

### Tenant Role Gating

Within a tenant, AI features will be gated by tenant roles (managed via Spatie permissions):

- **Asset Manager**: Can use asset intelligence features (tagging, metadata, deduplication)
- **Brand Manager**: Can use brand intelligence and creative generation features
- **Tenant Owner/Admin**: Can use all AI features and configure AI settings
- **Viewer/Editor**: May have limited or no AI feature access

**Implementation Note**: Permission checks will use existing Spatie permission system with new tenant-scoped permissions for AI features.

### Feature Availability Gating

Individual AI features can be enabled or disabled per tenant, independent of plan:

- Tenant-level feature toggles (via AI Dashboard)
- Admin override capabilities (for support scenarios)
- Feature deprecation handling (graceful degradation if features are removed)

**Implementation Note**: Feature toggles will be stored in tenant metadata or a dedicated `tenant_ai_features` table.

### Usage Quota Gating

Real-time quota enforcement will prevent usage beyond plan limits:

- Quota checks before AI operation execution
- Soft limits (warnings) and hard limits (blocking)
- Quota reset cycles (monthly, per billing period)
- Overage handling (block, allow with billing, or allow with warning)

**Implementation Note**: Quota system will integrate with `ai_agent_runs` table and extend to tenant-level tracking.

## Explicit Non-Goals

By design, tenant AI will NOT perform the following actions:

### No Auto-Publishing

AI will never automatically publish assets, make assets public, or change asset visibility without explicit user confirmation.

### No Silent Asset Modification

AI will never silently modify asset content, metadata, or relationships. All modifications require explicit user approval.

### No Autonomous Spending

AI will never autonomously consume paid API credits or exceed quota limits. Quota checks occur before execution, and operations are blocked when limits are reached.

### No Bypassing Approvals

AI will never bypass existing approval workflows. If an asset requires approval before publishing, AI suggestions do not change that requirement.

### No Bypassing Plan Limits

AI will never bypass subscription plan limits. If a plan limits asset storage to 1GB, AI cannot generate assets that exceed that limit.

### No Tenant Data Access Violation

AI will never access data from other tenants, even for "learning" or "improvement" purposes. All AI operations are strictly scoped to the tenant's own data.

### No Autonomous Workflow Changes

AI will never automatically modify tenant workflows, settings, or configurations based on usage patterns. Recommendations are provided; changes require explicit confirmation.

## Relationship to AI Dashboard (Phase 8)

All tenant AI features will be governed and surfaced via the AI Dashboard (planned for Phase 8). The dashboard serves as the control plane for AI.

### Dashboard Responsibilities

- **Feature Toggles**: Enable/disable specific AI features per tenant
- **Usage Monitoring**: Real-time and historical usage tracking per feature
- **Cost Transparency**: Display costs per feature, per tenant, with breakdowns
- **Quota Management**: Set quota limits, view usage against quotas, configure alerts
- **Analytics**: Usage trends, popular features, cost optimization insights
- **Settings**: Configure AI behavior, default models, feature defaults

### Tenant-Facing Dashboard

Tenants will have access to a simplified dashboard showing:

- Their own AI usage and costs
- Available features for their plan
- Quota status and remaining usage
- Recent AI operations and results

### Admin-Facing Dashboard

Staff will have access to an admin dashboard showing:

- All tenant AI usage across the platform
- Cost attribution and billing integration
- Feature adoption metrics
- Quota violation alerts
- Tenant-specific overrides and configurations

**Status**: AI Dashboard is planned for Phase 8 and is a hard dependency for tenant AI rollout.

## Relationship to Billing & Cost Controls (Phase 9)

Tenant AI usage will eventually be tracked, limited, and billed. **No billing logic exists in this phase.**

### Cost Attribution Model

Tenant AI operations will be attributed to tenants via:

- **Tenant ID**: All `ai_agent_runs` for tenant AI will include `tenant_id`
- **Feature Tagging**: Each operation tagged with feature identifier (e.g., `asset_tagging`, `image_generation`)
- **Cost Calculation**: Costs calculated based on model pricing and token usage, attributed to tenant

**Implementation Note**: This extends existing `ai_agent_runs` table with tenant-level attribution and feature tagging.

### Billing Integration

Tenant AI costs will integrate with billing system:

- **Included Credits**: Plans may include monthly AI credits (e.g., $10/month included)
- **Overage Billing**: Usage beyond included credits billed separately or blocked
- **Invoice Integration**: AI costs appear on tenant invoices with breakdown by feature
- **Cost Transparency**: Tenants see real-time cost estimates before executing operations

**Implementation Note**: Billing integration is planned for Phase 9 and depends on Phase 8 AI Dashboard.

### Quota Enforcement

Quota enforcement will occur at multiple levels:

- **Plan-Level Quotas**: Defined in plan configuration (e.g., 100 tags/month, 50 generations/month)
- **Real-Time Checking**: Quota checks before AI operation execution
- **Soft vs. Hard Limits**: Soft limits warn; hard limits block
- **Overage Policies**: Configurable policies (block, allow with billing, allow with warning)

**Implementation Note**: Quota system will integrate with existing plan infrastructure and new quota tracking tables.

### Cost Controls

Tenants will have control over AI spending:

- **Budget Limits**: Set maximum monthly AI spend (beyond plan credits)
- **Feature-Level Limits**: Set limits per feature (e.g., max $5/month on image generation)
- **Automatic Blocking**: Automatic blocking when limits are reached
- **Notification Settings**: Alerts when approaching or exceeding limits

**Implementation Note**: Cost controls will be part of AI Dashboard (Phase 8) and billing integration (Phase 9).

## Summary

This document defines the intended scope, boundaries, and non-goals of future tenant-facing AI features. It serves as a design specification and guardrail for future implementation phases.

### Key Takeaways

- **Tenant AI is NOT implemented yet**: This is a design-only document for future phases.
- **Clear separation**: Tenant AI and system AI are distinct domains with different purposes and cost attribution.
- **Safety first**: All tenant AI features require human confirmation and are gated by permissions, plans, and quotas.
- **Dependencies explicit**: Asset system, brand system, AI Dashboard (Phase 8), and billing integration (Phase 9) are hard dependencies.
- **Non-goals clear**: Tenant AI will never auto-publish, silently modify, autonomously spend, or bypass approvals or plan limits.

### Purpose

This document:
- **Locks intent**: Establishes clear boundaries and principles before implementation begins
- **Prevents scope creep**: Explicit non-goals prevent feature bloat and mission drift
- **Enables safe implementation**: Clear dependencies and principles guide implementation decisions
- **Serves as reference**: Future phases refer to this document for design decisions and boundary enforcement

### Next Steps

Before implementing tenant AI features:

1. Verify asset system and brand system are complete and stable
2. Implement AI Dashboard (Phase 8) as the control plane
3. Implement billing integration (Phase 9) for cost tracking and quotas
4. Define detailed feature specifications based on this design document
5. Implement permission system integration for tenant AI features
6. Build quota and cost tracking infrastructure
7. Implement tenant AI features one category at a time, starting with simplest (Asset Intelligence)

**Status**: All steps above are future work. No tenant AI implementation should begin until dependencies are met.
