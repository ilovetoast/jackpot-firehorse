import LegalPage, { LegalSection, LegalParagraph, LegalList } from '../../Components/Legal/LegalPage'

/**
 * Subprocessor List for Jackpot Brand Asset Management, LLC.
 *
 * Referenced by:
 * - Privacy Policy §5 (How we share personal information)
 * - Data Processing Addendum §6 and Annex 3
 *
 * Drafting posture (decisions logged in docs/compliance/PATH_TO_GDPR.md):
 * - 30-day advance notice before adding or replacing a Subprocessor.
 * - Notice delivered by updating this page + email to account admin contact.
 * - Categories of data processed described at a level a non-engineer can follow.
 * - Transfer mechanisms disclosed where cross-border transfer is involved.
 *
 * This is counsel-ready draft copy, not a substitute for legal review.
 */
export default function Subprocessors() {
    return (
        <LegalPage title="Subprocessors" effectiveDate="April 18, 2026">
            <LegalParagraph>
                This page lists the third-party service providers (<strong>"Subprocessors"</strong>) that
                Jackpot Brand Asset Management, LLC (<strong>"Jackpot"</strong>) engages to help deliver
                the Jackpot Services. A Subprocessor is a vendor that may process Personal Data on
                Jackpot's behalf under Jackpot's documented instructions.
            </LegalParagraph>

            <LegalParagraph>
                This page is incorporated by reference into the Jackpot{' '}
                <a href="/privacy" className="text-white hover:underline">Privacy Policy</a> and the
                Jackpot{' '}
                <a href="/dpa" className="text-white hover:underline">Data Processing Addendum</a>. In the
                event of a conflict, the applicable written agreement with the Customer controls.
            </LegalParagraph>

            <LegalSection id="s1" number="1" title="How we manage Subprocessors">
                <LegalParagraph>
                    Before engaging a Subprocessor to process Customer Personal Data, Jackpot performs
                    risk-proportionate due diligence on the vendor's security and data-protection
                    practices, and enters into a written agreement that imposes obligations no less
                    protective than those set out in our Data Processing Addendum. Jackpot remains
                    responsible to Customer for each Subprocessor's performance.
                </LegalParagraph>
                <LegalParagraph>
                    Jackpot provides Customers with at least <strong>thirty (30) days'</strong> advance
                    notice before adding or replacing a Subprocessor that processes Customer Personal
                    Data. Notice is provided by updating this page and by sending a notification to the
                    administrative email address on file for Customer's account. Customers may object
                    during the notice window under the process described in the Data Processing Addendum
                    §6.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s2" number="2" title="Infrastructure and storage">
                <LegalParagraph>
                    These Subprocessors host the Services and store Customer Content and account data.
                </LegalParagraph>
                <SubprocessorTable
                    rows={[
                        {
                            name: 'Amazon Web Services, Inc. (AWS)',
                            purpose:
                                'Primary cloud infrastructure, object storage (S3), managed databases, transactional email (SES), content delivery.',
                            data: 'Customer Content (assets and associated metadata), account and profile data, activity and audit logs, backups.',
                            location: 'United States (primarily us-east-1 / us-east-2).',
                            transfer: 'GDPR DPA in place; Standard Contractual Clauses available.',
                        },
                    ]}
                />
            </LegalSection>

            <LegalSection id="s3" number="3" title="Payments and billing">
                <LegalParagraph>
                    These Subprocessors handle subscription management and payment processing. Payment
                    card data is collected and stored by the payment processor, not by Jackpot.
                </LegalParagraph>
                <SubprocessorTable
                    rows={[
                        {
                            name: 'Stripe, Inc.',
                            purpose:
                                'Subscription management, payment processing, tax calculation, invoice delivery.',
                            data: 'Billing contact details, customer identifiers, payment metadata (card details are processed directly by Stripe).',
                            location: 'United States.',
                            transfer: 'Standard Contractual Clauses via Stripe DPA.',
                        },
                    ]}
                />
            </LegalSection>

            <LegalSection id="s4" number="4" title="AI and machine-learning providers">
                <LegalParagraph>
                    These Subprocessors provide the generative, analytical, and embedding models that
                    power Jackpot's AI-assisted features (tagging, metadata generation, image editing,
                    transcription, search). Jackpot configures these providers to disable training on
                    Customer Content where that option is offered.
                </LegalParagraph>
                <SubprocessorTable
                    rows={[
                        {
                            name: 'OpenAI, L.L.C.',
                            purpose:
                                'Generative text, image, and audio processing (including Whisper transcription) for AI-assisted features.',
                            data: 'Prompt text and attached assets submitted for processing; outputs returned to the Services.',
                            location: 'United States.',
                            transfer:
                                'Standard Contractual Clauses via OpenAI DPA; zero-retention / no-training configuration where available.',
                        },
                        {
                            name: 'Anthropic, PBC',
                            purpose:
                                'Generative text processing for brand-intelligence and long-form features.',
                            data: 'Prompt text and associated metadata submitted for processing; outputs returned to the Services.',
                            location: 'United States.',
                            transfer:
                                'Standard Contractual Clauses via Anthropic DPA; no-training configuration where available.',
                        },
                        {
                            name: 'Google LLC (Gemini / Vertex AI)',
                            purpose: 'Generative image editing and multimodal processing.',
                            data: 'Images and prompts submitted for processing; outputs returned to the Services.',
                            location: 'United States / global (Google cloud regions).',
                            transfer:
                                'Standard Contractual Clauses via Google Cloud DPA.',
                        },
                        {
                            name: 'Black Forest Labs GmbH (FLUX)',
                            purpose: 'Generative image model for AI-assisted image creation.',
                            data: 'Prompt text and reference images submitted for processing; outputs returned to the Services.',
                            location: 'European Union (Germany).',
                            transfer: 'In-EEA processing; no Restricted Transfer for EEA data.',
                        },
                        {
                            name: 'Image embedding provider',
                            purpose:
                                'Vector embedding of images to power visual search and similarity features.',
                            data: 'Image URLs and model identifiers.',
                            location: 'Varies by deployment configuration.',
                            transfer:
                                'Vendor and transfer mechanism disclosed to Customers on request; Jackpot is reviewing formalization.',
                        },
                    ]}
                />
            </LegalSection>

            <LegalSection id="s5" number="5" title="Communications and notifications">
                <LegalParagraph>
                    These Subprocessors deliver transactional email, push notifications, and similar
                    communications.
                </LegalParagraph>
                <SubprocessorTable
                    rows={[
                        {
                            name: 'Postmark (Wildbit / ActiveCampaign, LLC)',
                            purpose: 'Transactional email delivery.',
                            data: 'Recipient email address, sender, subject, message body.',
                            location: 'United States.',
                            transfer: 'Standard Contractual Clauses via Postmark DPA.',
                        },
                        {
                            name: 'Resend, Inc.',
                            purpose: 'Transactional email delivery (alternate / fallback provider).',
                            data: 'Recipient email address, sender, subject, message body.',
                            location: 'United States.',
                            transfer: 'Standard Contractual Clauses via Resend DPA.',
                        },
                        {
                            name: 'Railsware Products, Inc. (Mailtrap)',
                            purpose: 'Email testing and staging (non-production environments).',
                            data: 'Recipient email address, sender, subject, message body (staging only).',
                            location: 'United States / European Union.',
                            transfer: 'Standard Contractual Clauses via Mailtrap DPA.',
                        },
                        {
                            name: 'OneSignal, Inc.',
                            purpose:
                                'Web-push notification delivery for users who have opted in to push notifications.',
                            data: 'Device push tokens and an opaque external identifier tied to the user account.',
                            location: 'United States.',
                            transfer: 'Standard Contractual Clauses via OneSignal DPA.',
                        },
                    ]}
                />
            </LegalSection>

            <LegalSection id="s6" number="6" title="Operations, monitoring, and support">
                <LegalParagraph>
                    These Subprocessors help Jackpot keep the Services running reliably and respond to
                    operational issues.
                </LegalParagraph>
                <SubprocessorTable
                    rows={[
                        {
                            name: 'Functional Software, Inc. (Sentry)',
                            purpose: 'Application error and performance monitoring.',
                            data:
                                'Stack traces, environment and request metadata. Jackpot configures Sentry with personal-data minimization enabled (send_default_pii = false).',
                            location: 'United States.',
                            transfer: 'Standard Contractual Clauses via Sentry DPA.',
                        },
                    ]}
                />
            </LegalSection>

            <LegalSection id="s7" number="7" title="Affiliates">
                <LegalParagraph>
                    Jackpot may rely on its affiliates (entities under common control with Jackpot) to
                    help deliver the Services. Where an affiliate processes Customer Personal Data, it is
                    bound by written obligations no less protective than those set out in this page and
                    in the Data Processing Addendum. A current list of affiliates is available on request.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s8" number="8" title="Service providers that are not Subprocessors">
                <LegalParagraph>
                    The following categories of vendor are not listed here because they either do not
                    process Customer Personal Data or process only Jackpot's own business records as
                    independent controllers or their own service providers:
                </LegalParagraph>
                <LegalList
                    items={[
                        <>Domain registrars, DNS, and certificate authorities;</>,
                        <>Identity providers used by Jackpot personnel (not by Customer users);</>,
                        <>Internal productivity tools (e.g., document collaboration, calendars);</>,
                        <>Professional-services providers (e.g., accountants, outside counsel) that receive Customer information only as necessary to perform services for Jackpot and are bound by professional or contractual confidentiality obligations.</>,
                    ]}
                />
            </LegalSection>

            <LegalSection id="s9" number="9" title="Contact">
                <LegalParagraph>
                    Questions about this page, or a request to receive notifications of future changes,
                    may be sent to{' '}
                    <a className="text-white hover:underline" href="mailto:privacy@jackpotbam.com">
                        privacy@jackpotbam.com
                    </a>
                    . Objections to a new Subprocessor during the notice period should be sent to{' '}
                    <a className="text-white hover:underline" href="mailto:legal@jackpotbam.com">
                        legal@jackpotbam.com
                    </a>{' '}
                    under the process in Section 6 of the Data Processing Addendum.
                </LegalParagraph>
            </LegalSection>

            <div className="mt-16 rounded-xl border border-white/[0.06] bg-white/[0.02] p-6 text-sm text-white/60">
                <p className="font-semibold text-white">Jackpot Brand Asset Management, LLC</p>
                <p className="mt-1">An Ohio limited liability company</p>
                <p className="mt-3">Ohio</p>
                <p className="mt-3">
                    Privacy inquiries:{' '}
                    <a className="text-white hover:underline" href="mailto:privacy@jackpotbam.com">
                        privacy@jackpotbam.com
                    </a>
                </p>
                <p>
                    Legal notices:{' '}
                    <a className="text-white hover:underline" href="mailto:legal@jackpotbam.com">
                        legal@jackpotbam.com
                    </a>
                </p>
            </div>
        </LegalPage>
    )
}

/**
 * Compact, screen-reader-friendly table of Subprocessors for a given category.
 * Renders as a stack of cards on small screens and a single table on wide screens.
 */
function SubprocessorTable({ rows }) {
    return (
        <div className="mt-4 space-y-4">
            {rows.map((row, idx) => (
                <div
                    key={`${row.name}-${idx}`}
                    className="rounded-xl border border-white/[0.06] bg-white/[0.015] p-5"
                >
                    <div className="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                        <h4 className="text-base font-semibold text-white">{row.name}</h4>
                        <p className="text-xs text-white/40">{row.location}</p>
                    </div>
                    <dl className="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-[8rem_1fr]">
                        <dt className="text-white/45">Purpose</dt>
                        <dd className="text-white/75">{row.purpose}</dd>

                        <dt className="text-white/45">Data</dt>
                        <dd className="text-white/75">{row.data}</dd>

                        <dt className="text-white/45">Transfers</dt>
                        <dd className="text-white/75">{row.transfer}</dd>
                    </dl>
                </div>
            ))}
        </div>
    )
}
