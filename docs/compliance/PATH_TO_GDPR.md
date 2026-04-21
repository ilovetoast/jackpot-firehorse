# Path to GDPR

Living checklist for bringing Jackpot Brand Asset Management, LLC (`jackpotbam.com`) into GDPR compliance. Check items off as they ship.

**Owner:** Jackpot LLC
**Privacy contact:** `support@jackpotbam.com` (mailbox pending)
**Status legend:** `[ ]` not started · `[~]` in progress · `[x]` done · `[-]` deferred / not applicable

See the full audit canvas: `canvases/gdpr-compliance-gap-analysis.canvas.tsx`.

---

## Phase 0 — Foundation

- [x] Initial codebase gap analysis (canvas)
- [x] Path-to-GDPR tracking doc (this file)
- [ ] Nominate internal privacy contact and publish email
- [ ] See **Go-Live Mailbox Setup** section below

### Go-Live mailbox setup (required before public launch)

These are referenced from `/terms`, `/accessibility`, and will be referenced from `/privacy`, `/dpa`, `/subprocessors`, and `SECURITY.md`.

- [ ] `support@jackpotbam.com` — general inquiries, accessibility feedback, data-subject-rights intake
- [ ] `legal@jackpotbam.com` — legal notices (contract, IP, arbitration opt-out, DSR escalations)
- [ ] `security@jackpotbam.com` — vulnerability reports, incident notifications (per `SECURITY.md`)
- [ ] `privacy@jackpotbam.com` — privacy policy contact and DPO-style inquiries (can alias to `legal@` initially)
- [ ] `abuse@jackpotbam.com` — acceptable-use violation reports (standard internet hygiene)
- [ ] `sales@jackpotbam.com` — inbound leads (replaces or aliases the current `SALES_NOTIFY_EMAIL` env value)
- [ ] DMARC / SPF / DKIM records on `jackpotbam.com` MX
- [ ] Shared inbox or ticketing routing so nothing falls on the floor
- [ ] **Resolve "Velvetysoft" posture** — either (a) incorporate Velvetysoft, LLC (or equivalent) and paper the Jackpot↔Velvetysoft relationship, (b) file a DBA of "Jackpot Brand Asset Management, LLC d/b/a Velvetysoft" in Ohio, or (c) remove the "Velvetysoft" link from `resources/js/Components/AppFooter.jsx` and any other surface. Current public claim at `velvetysoft.com` is not backed by a legal entity.

---

## Phase 1 — Legal surface (Week 1–2)

### Public legal pages

- [x] Draft Terms of Service (`/terms`) — `resources/js/Pages/Legal/Terms.jsx`
- [x] Draft Privacy Policy (`/privacy`) — `resources/js/Pages/Legal/Privacy.jsx`
- [x] Draft Data Processing Addendum (`/dpa`) — `resources/js/Pages/Legal/DPA.jsx`
- [x] Draft Accessibility Statement (`/accessibility`) — `resources/js/Pages/Legal/Accessibility.jsx`
- [x] Draft Subprocessor list (`/subprocessors`) — `resources/js/Pages/Legal/Subprocessors.jsx`
- [x] Cookie policy section included inline in Privacy Policy §9
- [x] Shared `LegalPage.jsx` layout wrapper — `resources/js/Components/Legal/LegalPage.jsx`
- [x] Routes registered in `routes/web.php` for Terms + Privacy + DPA + Subprocessors + Accessibility
- [x] Footer links on marketing pages (Terms + Privacy + DPA + Subprocessors + Accessibility)
- [x] In-app footer link to legal pages (authenticated surfaces) — `resources/js/Components/AppFooter.jsx`
- [ ] Counsel review of all legal pages before public launch — see **Counsel review packet** below
- [x] Cookie banner referenced in Privacy §9 — shipped in Phase 2 (`CookieConsentBanner.jsx`)
- [ ] Honor Global Privacy Control signal (referenced in Privacy §9) in session handling

### Security floor (Art. 32)

- [ ] `URL::forceScheme('https')` in `AppServiceProvider::boot` for production
- [ ] `SESSION_ENCRYPT=true` in production env
- [ ] `SESSION_LIFETIME` reduced to ≤ 20160 minutes (14 days)
- [ ] `SESSION_SECURE_COOKIE=true` in production
- [ ] `SESSION_SAME_SITE=lax` (already default, verify)
- [ ] `throttle:5,1` on `POST /gateway/login` in `routes/web.php`
- [ ] `SECURITY.md` with `security@jackpotbam.com` + 72-hour breach process
- [ ] Admin 2FA / MFA (TOTP)

### Counsel review packet

Send the following to outside counsel before `jackpotbam.com` goes public. Everything below is drafted from a "maximum control, minimum commitment" posture — counsel should focus on (a) enforceability against consumers in Ohio and California; (b) GDPR/UK GDPR/Swiss FADP defensibility of the Privacy Policy and DPA; (c) CCPA/CPRA Service-Provider status under the DPA Section 12; (d) the arbitration and class-waiver clauses given the latest Ninth Circuit and California-state-court rulings.

**Documents to review:**
- `resources/js/Pages/Legal/Terms.jsx` (`/terms`)
- `resources/js/Pages/Legal/Privacy.jsx` (`/privacy`)
- `resources/js/Pages/Legal/DPA.jsx` (`/dpa`)
- `resources/js/Pages/Legal/Subprocessors.jsx` (`/subprocessors`)
- `resources/js/Pages/Legal/Accessibility.jsx` (`/accessibility`)

**Decisions already baked in (for counsel awareness, see decisions log below for rationale):**
- Governing law: Ohio; venue: Franklin County, OH; arbitration: AAA + class-action waiver + 30-day opt-out.
- Liability cap: greater of $100 or 12 months' fees.
- Refund policy: no refunds; trials non-refundable.
- DSR response window: 30 days, manual fulfillment.
- Deletion: self-service button does NOT equal statutory erasure; full erasure by request to `privacy@jackpotbam.com`; retention exceptions itemized in Privacy §8.
- Backups: ~30-day rolling AWS backup window, data treated as "beyond use" per ICO guidance.
- Subprocessor change notice: 30 days; objection remedy is terminate-only.
- Audit rights: SOC 2 / ISO 27001 attestations primary; direct audit once per 12 months at Customer cost if insufficient.
- Breach notice (processor → controller): without undue delay, no later than 48 hours.
- SCCs: EU 2021 Module 2 / Module 3 + UK Addendum + Swiss amendments; Clause 17/18 Irish law/courts (confirm with counsel).
- CCPA/CPRA: no sale, no share, no cross-context behavioral advertising; Service Provider status under DPA §12.
- Minimum user age: 18+.
- Accessibility: WCAG 2.1 AA aspirational, good-faith language, expressly disclaims warranties and hard SLAs.

**Known open items counsel should weigh in on:**
- Whether Ireland is the right Clause 17/18 forum for a US-based processor, vs. Germany, Netherlands, or a US-law carve-out for non-EEA Customers.
- Whether to name an EU/UK Art. 27 representative pre-launch or wait until EU customer acquisition begins.
- Trademark use — `™` only (registrations pending), not `®`. Verify ITU/use-in-commerce status for the `Jackpot` word mark and logo before publishing.
- Whether the "Powered by Velvetysoft" link in `resources/js/Components/AppFooter.jsx` is appropriate given that Velvetysoft is not currently a registered entity or DBA of Jackpot LLC.
- Adequacy of the "beyond use" backup language for ICO and CNIL investigations.
- Whether the DSR "one-time code to email on file" verification satisfies the CCPA "reasonable method" standard for high-risk requests.

### Subprocessor paperwork (Art. 28, Art. 44–49)

For every item below: signed DPA on file AND entry on the `/subprocessors` page.

- [ ] AWS (S3, SES) — GDPR DPA + SCCs
- [ ] Stripe — DPA (auto)
- [ ] OpenAI — DPA + enable zero-retention / no-training
- [ ] Anthropic — DPA + no-training
- [ ] Google Gemini / Vertex — DPA
- [ ] Black Forest Labs (FLUX) — DPA / ToS review
- [ ] Custom image embedding API — identify vendor, DPA
- [ ] Sentry — DPA (`send_default_pii=false` ✓ already)
- [ ] OneSignal — DPA
- [ ] Mailtrap — DPA
- [ ] Postmark — DPA
- [ ] Resend — DPA

---

## Phase 2 — Rights & consent (Week 3–6)

### Consent (Art. 6, 7, ePrivacy)

- [x] `CookieConsentBanner` + `cookieConsent.js` utilities — `resources/js/Components/CookieConsentBanner.jsx`
- [x] `consents` table — `database/migrations/2026_04_21_120000_create_consents_table.php` (`purpose`, `policy_version`, `granted_at`, `ip_address`, `user_agent`; `user_id` nullable)
- [x] `POST /privacy/consent` — `CookieConsentController` (throttled), records append-only consent rows
- [x] Gate OneSignal Web SDK behind functional consent — `config/privacy.php` `gate_onesignal_behind_consent` + `pushService.loadOneSignalSdkIfConfigured()`
- [x] Gate client performance metrics (`/app/performance/client-metric`) behind analytics consent — `performanceTracking.js` + `allowsAnalyticsCookies()`
- [x] Granular preferences UI — `PrivacyPreferences.jsx` on Profile → Privacy & cookies (`#privacy-cookies`)
- [x] `AI_LOG_GENERATIVE_PROMPTS` default `false` in `config/ai.php` (set `AI_LOG_GENERATIVE_PROMPTS=true` in `.env` only if you need generative prompt audit logs)
- [x] Shared Inertia props: `privacy.*` (region, GPC, policy version, server-side consent snapshot) — `HandleInertiaRequests`
- [x] `window.__jackpotPrivacyBootstrap` — `app.blade.php` (aligns first paint with consent checks before React hydrates)

**Run migration:** `php artisan migrate`

### Data subject rights (Art. 15, 17, 20, 21)

- [x] `GET /app/profile/export` → ZIP (`jackpot-user-data.json`) — `ProfileController@exportData` + `UserPersonalDataExportService` (throttle 10/hr)
- [x] `POST /app/profile/erasure-request` → `data_subject_requests` row (pending) — `ProfileController@requestErasure` (throttle 5/hr)
- [x] `AnonymizeUserPersonalDataJob` + `UserPersonalDataAnonymizer` — sessions, `activity_events`, `ai_agent_runs`, `frontend_errors`, `contact_leads`, `asset_approval_comments`, `consents`, account email/profile + suspend
- [x] Per-user rectification paths already covered by `ProfileController@update` ✓
- [x] Object-to-processing path for `contact_leads` (extend `unsubscribed_at` pattern) — `POST /privacy/contact-leads/object-to-processing`, `GET /privacy/object-lead`, `processing_objected_at` on `contact_leads`
- [x] Admin UI — `/app/admin/data-subject-requests` (`DataSubjectRequestController` + `Admin/DataSubjectRequests/Index.jsx`) approve / reject erasure

**Run migration:** `php artisan migrate` (adds `data_subject_requests`).

### Retention (Art. 5(1)(e))

- [ ] Implement `PruneAILogs` (currently a stub) — default 90 days
- [ ] `PruneActivityEvents` command — default 13 months
- [ ] `PruneFrontendErrors` command — default 30 days
- [ ] `PruneSessions` command — default 30 days idle
- [ ] `PruneAssetMetrics` command — default 13 months
- [ ] `PruneContactLeads` command — default 24 months unresponded
- [ ] `collection_invitations.expires_at` column + prune
- [ ] Scheduled job entries in `routes/console.php`
- [ ] Retention schedule documented on `/privacy`

---

## Go Live — Explosions, operational data & cost control

**Why this exists:** A single bad day (error spike, bug, noisy client, or misconfigured automation) can **fan out** into huge row counts, API spend, and support load — *before* normal “steady state” retention even matters. That breaks **Art. 5(1)(e)** (storage minimisation) in practice if we only plan *calendar* retention but not *runaway* ingestion. This section tracks **cascade risks**, **monitoring**, and **preemption** alongside the pruning work above.

**Operational runbook:** `docs/operations/DATA_EXPLOSION_RUNBOOK.md` (triage steps, example SQL, links to alert/ticket runbook and env caps).

**Definition — “explosion”:** Any automated path where **one upstream event** creates **many dependent rows or AI calls** (tickets → AI classification/summaries, errors → Sentry sync → incidents, uploads → pipeline runs, client telemetry → performance tables, etc.), or where **unbounded append-only logs** grow without budgets.

### Best practices (product + infra)

1. **Rate limits & caps** — Per-tenant and global caps on alert creation, auto-ticket creation, and similar fan-out jobs; **circuit breakers** when a cap trips (log + suppress, never silent infinite loop).
2. **Idempotency & deduplication** — Same fingerprint should not mint thousands of distinct actionable rows (tickets, incidents) without human review.
3. **Cascade maps** — Document “if table X spikes, what jobs fire?” (queues, AI agent runs, notifications). One row in `tickets` must not unconditionally enqueue unbounded `ai_agent_runs` / `ai_ticket_suggestions`.
4. **Observability** — Dashboards or scheduled checks on **row growth rate** (hourly/daily deltas) for hot tables; alerts when growth exceeds a threshold (not just absolute size).
5. **Cost guards** — AI: token budgets, per-tenant caps, kill switches for non-critical summarisation during incidents. DB: index review on high-insert tables; archive/cold storage for raw logs where appropriate.
6. **Runbooks** — “Ticket storm” / “error storm” playbooks: disable auto-ticket rule, raise caps visibility, pause AI ticket classification if needed.
7. **Tie to retention** — Every high-volume store should have a **named owner**, **max useful retention**, and a **prune/archive** path (see **Retention (Art. 5(1)(e))** above). Explosion controls reduce *peak* damage; pruning reduces *chronic* storage.

### Codebase audit — high-volume / cascade-prone stores (snapshot)

*This is a working inventory for go-live planning; extend as new features ship.*

| Category | Tables / artifacts (examples) | Typical risk |
|----------|-------------------------------|--------------|
| **Alerts → tickets (fan-out)** | `alert_candidates`, `support_tickets` / `tickets`, `ticket_links` | Storm of alerts → mass auto-created tickets; downstream notifications & SLA timers. |
| **AI on tickets** | `ai_ticket_suggestions` (`AITicketSuggestion`), `TicketClassificationService`, related metadata on tickets | Each new ticket can trigger classification / duplicate detection → extra **AI** calls and rows. |
| **AI agent & usage** | `ai_agent_runs`, `ai_usage`, `ai_usage_logs`, AI metadata suggestion tables | Unbounded runs from automations, editor, tagging, pipelines. |
| **Audit & activity** | `activity_events` | High churn tenants → very large audit history. |
| **Client / app errors** | `frontend_errors`, `application_error_events` | Client error POST storms; AI agent failures also write `application_error_events`. |
| **Performance telemetry** | `performance_logs`, `client_performance_metrics` | Client metrics if misconfigured or sampled too aggressively. |
| **Processing & assets** | `asset_processing_logs`, `asset_derivative_failures`, upload/zip failure escalations | Per-asset or per-job rows during bulk operations. |
| **Ops & incidents** | `system_incidents`, `analysis_events`, `sentry_issues` (synced) | Incident dedup matters; Sentry issue volume. |
| **Brand / PDF / pipeline** | `brand_pipeline_runs`, `brand_pdf_pages`, `pdf_text_extractions`, `asset_pdf_pages`, embeddings-related tables | Large backfills = many rows + AI. |
| **Marketing & consent** | `contact_leads`, `consents` (append-only) | Lower storm risk but needs retention alignment. |
| **DSR & sessions** | `data_subject_requests`, `sessions` | Bounded by nature; sessions still need idle pruning. |

**Existing safety valves (verify in production env):**

- `config/alerts.php` — `max_per_tenant_per_hour` (alert candidates), `tickets.max_auto_create_per_hour` (auto-created support tickets). `AutoTicketCreationService` enforces ticket cap and logs suppression.
- Ticket / alert pipeline: `AutoTicketCreationService`, `TicketCreationService`, escalation services (upload, zip, derivative failures) — confirm rules don’t multiply work during systemic failures.

### Go-live checklist — explosions & monitoring

- [ ] **Runbook** — “Alert/ticket storm” + “AI cost spike” — use `docs/operations/DATA_EXPLOSION_RUNBOOK.md` + `docs/RUNBOOK_ALERTS_AND_TICKETS.md`; assign owners.
- [ ] **Dashboards / queries** — Weekly or daily **growth rate** on `ai_agent_runs`, `activity_events`, `frontend_errors`, `alert_candidates`, `support_tickets` / internal `tickets` (as applicable).
- [ ] **Env review** — `ALERTS_MAX_PER_TENANT_PER_HOUR`, `TICKETS_MAX_AUTO_CREATE_PER_HOUR` set appropriately for launch traffic; document chosen values.
- [ ] **Cascade test** — Exercise or document behaviour when error rate 10×s (simulate): caps suppress new auto-tickets; no unbounded AI summarisation on suppressed tickets.
- [ ] **Link AI features to budgets** — Confirm ticket AI classification / duplicate detection cannot exhaust API quota without admin visibility.
- [ ] **Align with pruning** — Explosion controls + **Retention (Art. 5(1)(e))** commands share the same goal: bounded operational data over time.

---

## Phase 3 — Maturity (Week 6–12)

- [ ] Records of Processing Activities (ROPA) document (Art. 30)
- [ ] Data Protection Impact Assessment for the AI metadata pipeline (Art. 35)
- [ ] EU data residency option (eu-west-1 S3 + EU-hosted AI providers, tenant.region flag)
- [ ] Standard Contractual Clauses executed with all US-hosted processors
- [ ] Transfer Impact Assessment documented
- [ ] Formal breach response runbook (`docs/operations/BREACH_RESPONSE.md`)
- [ ] Tabletop breach drill
- [ ] Vendor review cadence (quarterly subprocessor review)

---

## Open questions / decisions log

Document decisions here as we make them so the drafts stay consistent.

| Date | Topic | Decision | Who |
|------|-------|----------|-----|
| 2026-04-18 | Entity name | Jackpot Brand Asset Management, LLC (d/b/a Jackpot™) | Founder |
| 2026-04-18 | Primary domain | jackpotbam.com | Founder |
| 2026-04-18 | Contact email | support@jackpotbam.com (legal@, security@, privacy@, abuse@, sales@ aliases TBD — see Go-Live Mailbox Setup) | Founder |
| 2026-04-18 | Registered business address | 100 Pheasant Woods Ct, Loveland, OH 45140 | Founder |
| 2026-04-18 | Governing law for T&Cs | Ohio | Founder |
| 2026-04-18 | Venue / arbitration seat | Franklin County, Ohio | Founder |
| 2026-04-18 | Dispute resolution | Binding AAA arbitration + class-action waiver + 30-day opt-out for natural persons | Founder |
| 2026-04-18 | Minimum user age | 18+ | Founder |
| 2026-04-18 | Service audience | B2B primary; individual/consumer accounts allowed | Founder |
| 2026-04-18 | AI-credit forfeiture | Monthly forfeiture; no cash value; revoked on downgrade/cancel | Founder |
| 2026-04-18 | Price-change notice | 30 days before renewal for subscription fees; next billing period for usage/credits | Founder |
| 2026-04-18 | Anonymized-data usage scope | Service improvement, analytics, benchmarking, internal ML/AI development. **No** cross-customer external model training of identifiable PII or Customer Confidential Info except per Privacy Policy / DPA | Founder |
| 2026-04-18 | Liability cap | Greater of $100 or 12 months fees paid | Founder |
| 2026-04-18 | Trademark mark | ™ (registration pending with USPTO) | Founder |
| 2026-04-18 | Refund policy | No refunds for partial periods, unused subscription time, or unused AI Credits. Trials are non-refundable. | Founder |
| 2026-04-18 | Public-facing SLA claims | Removed from marketing copy. Specific numeric claims (99.9% uptime, <200ms latency) replaced with aspirational language ("Near-zero downtime, by design," "Global edge delivery"). Any formal numeric SLA will be offered only in signed Order Forms, per T&C §15. | Founder |
| 2026-04-18 | GDPR role | Dual: controller for signup/marketing/billing/website; processor for Customer Content (governed by DPA) | Founder |
| 2026-04-18 | Data residency disclosed to users | US-hosted (AWS us-east-1/us-east-2); international transfers disclosed; SCCs relied on where relevant | Founder |
| 2026-04-18 | DSR response window | 30 days (GDPR default), extendable where law permits | Founder |
| 2026-04-18 | DSR verification | Logged-in account request suffices; otherwise email-match + one-time code to the email on file | Founder |
| 2026-04-18 | Cookie consent UX | Opt-in banner for EEA/UK/Switzerland visitors (geo-gated); informational notice elsewhere | Founder |
| 2026-04-18 | Marketing email basis | Legitimate interest / soft opt-in for business customers + prospects; always-available unsubscribe | Founder |
| 2026-04-18 | CCPA/CPRA posture | No sale, no share, no targeted advertising, no profiling with legal effect — flat denial | Founder |
| 2026-04-18 | Children | Services not directed to anyone under 18; do not knowingly collect; delete on discovery | Founder |
| 2026-04-18 | Breach notification posture | Notify without undue delay and as required by applicable law; no specific timeline commitment beyond that | Founder |
| 2026-04-18 | Public retention posture | Soft — publish categories + ordering-of-magnitude language only; no specific numbers we cannot enforce today. Specific numbers will be published after Phase 2 retention jobs ship (`PruneAILogs`, `PruneActivityEvents`, etc.). | Founder |
| 2026-04-18 | Backup / DR posture | Deleted data may remain in secure encrypted rolling backups for up to ~30 days before overwrite; during that window treated as "beyond use" per ICO guidance — not accessed for operational, analytical, product-development, or AI-training purposes | Founder |
| 2026-04-18 | DSR fulfillment mode | Manual by our team within the 30-day statutory window. Policy explicitly states this and directs users to `privacy@jackpotbam.com` rather than in-product buttons | Founder |
| 2026-04-18 | Account deletion scope | Self-service in-product button hard-deletes the `users` row only. Full Art. 17 erasure (activity logs, AI logs, audit trail, Sentry issues, backups) is processed **manually by request** to `privacy@jackpotbam.com`. Policy reflects this. Automated cascade is a Phase 2 deliverable (`AnonymizeUserJob`). | Founder |
| 2026-04-18 | Deletion-not-absolute exceptions published | Yes — contract completion, tax/accounting, fraud/security, legal hold, legal-claim defense, free speech, compatible internal use | Founder |
| 2026-04-18 | DPA — subprocessor change notice | 30 days via `/subprocessors` page + email to admin contact; Customer may object within the notice window | Founder |
| 2026-04-18 | DPA — subprocessor objection remedy | Terminate-only: if Jackpot cannot offer a commercially reasonable alternative, Customer may terminate the affected Services without penalty (sole and exclusive remedy) | Founder |
| 2026-04-18 | DPA — audit rights | Third-party attestations (SOC 2 Type II / ISO 27001) primary; one direct audit per 12 months at Customer cost, 30 days' notice, NDA, business hours, remote/documentary where possible | Founder |
| 2026-04-18 | DPA — breach notice to Controller | Without undue delay; no later than 48 hours after becoming aware (leaves Controllers ~24h runway to the 72h GDPR deadline) | Founder |
| 2026-04-18 | DPA — end-of-service | 30 days to return / delete, extendable to 90 days on written request; then backup "beyond use" window ~30 days | Founder |
| 2026-04-18 | DPA — SCC modules | EU SCCs Module 2 (C→P) and Module 3 (P→P); UK Addendum; Swiss amendments; Jackpot is data importer | Founder |
| 2026-04-18 | DPA — SCC Clause 17 / 18 | Irish law / Irish courts | Founder (default — confirm with counsel; Ireland is the market-standard choice for non-EU processors) |
| 2026-04-18 | DPA — liability | Subject to the Agreement's limitation-of-liability cap (aggregate, not separate) | Founder |
| 2026-04-18 | DPA — Annex 2 TOMs | Published at aspirational/descriptive level matching current implementation; no specific certification claims (SOC 2 / ISO 27001 language scoped as "where available") | Founder |
| 2026-04-18 | Subprocessor list — categorization | Grouped by function (Infrastructure, Payments, AI/ML, Communications, Operations). Each entry discloses purpose, data categories, processing location, and transfer mechanism. Affiliates disclosed as a category; non-subprocessor vendors (registrars, counsel, internal tools) explicitly excluded. | Founder |
| 2026-04-18 | Subprocessor — custom image embedding provider | Listed as "Image embedding provider" with vendor identity disclosed on request, pending formalization. Flagged in counsel-review packet. | Founder |
| 2026-04-18 | Marketing footer copyright | Simplified to `© YYYY Jackpot LLC`. The explicit `™` attribution moved to the Terms page (already present) rather than the footer, because the trademark assertion only needs to appear once per logical surface. "All rights reserved" removed as a stylistic choice (no legal force under current copyright law). | Founder |
| 2026-04-18 | "Powered by Velvetysoft" posture | Existing link in `Components/AppFooter.jsx` left in place pending a decision by the founder to either (a) incorporate Velvetysoft as an entity or file a DBA of Jackpot LLC, or (b) remove the link. NOT added to the marketing footer. Flagged in counsel-review packet and go-live todo. | Founder |
| 2026-04-21 | Phase 2 — cookie consent | EEA/UK/CH strict opt-in via `CF-IPCountry` (when present) + static ISO list; GPC (`Sec-GPC: 1`) forces analytics + marketing off; OneSignal SDK deferred until functional consent when `GATE_ONESIGNAL_BEHIND_CONSENT=true` (default) | Founder |
| 2026-04-21 | Phase 2 — AI prompt logging default | `AI_LOG_GENERATIVE_PROMPTS` defaults to `false` in config; opt-in via env for debugging | Founder |
| — | EU / UK Art. 27 representative | Not appointed (not required until EU-facing go-to-market ramps); revisit when EU customers are onboarded | _pending_ |
| — | Counsel review before public launch | _pending — acknowledged, scheduled before domain goes live_ | _pending_ |

---

## Linked artifacts

- Gap analysis canvas: `canvases/gdpr-compliance-gap-analysis.canvas.tsx`
- Operational safety: `config/alerts.php` — alert / auto-ticket rate caps (explosion controls)
- `docs/operations/DATA_EXPLOSION_RUNBOOK.md` — incident triage, monitoring SQL, cross-links
- `resources/js/Pages/Legal/Terms.jsx`
- `resources/js/Pages/Legal/Privacy.jsx`
- `resources/js/Pages/Legal/DPA.jsx`
- `resources/js/Pages/Legal/Subprocessors.jsx`
- `resources/js/Pages/Legal/Accessibility.jsx`
- `resources/js/Components/Legal/LegalPage.jsx`
- (future) `SECURITY.md`
- (future) `docs/compliance/ROPA.md`
- (future) `docs/operations/BREACH_RESPONSE.md`
