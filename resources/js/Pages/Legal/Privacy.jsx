import LegalPage, { LegalSection, LegalParagraph, LegalList } from '../../Components/Legal/LegalPage'

/**
 * Privacy Policy for Jackpot Brand Asset Management, LLC.
 *
 * Drafting posture (decisions logged in docs/compliance/PATH_TO_GDPR.md):
 * - Role: dual — controller for signup / marketing / billing / website; processor for
 *   Customer Content processed on behalf of Customer (DPA governs the processor role).
 * - Hosting: US-based (AWS us-east-1 / us-east-2). International transfers disclosed;
 *   Standard Contractual Clauses relied on where relevant.
 * - DSR response window: 30 days (GDPR default).
 * - DSR verification: login if possible; otherwise email-match plus a one-time code to
 *   the email on file.
 * - Cookies: opt-in banner for EEA / UK / Swiss visitors; informational notice for others.
 * - Marketing email: legitimate-interest, soft-opt-in with always-available unsubscribe.
 * - CCPA/CPRA: no "sale," no "share," no cross-context behavioral advertising.
 * - Children: services not directed to anyone under 18; do not knowingly collect minors' data.
 * - Breach: notify as required by applicable law; no specific timeline commitment beyond that.
 *
 * This is counsel-ready draft copy, not a substitute for legal review.
 */
export default function Privacy() {
    return (
        <LegalPage title="Privacy Policy" effectiveDate="April 18, 2026">
            <LegalParagraph>
                This Privacy Policy explains how <strong>Jackpot Brand Asset Management, LLC</strong>,
                an Ohio limited liability company doing business as <strong>Jackpot™</strong>
                ("Jackpot," "we," "us," or "our"), collects, uses, shares, retains, and protects personal
                information in connection with the Jackpot website at{' '}
                <a className="text-white hover:underline" href="https://jackpotbam.com">jackpotbam.com</a>,
                the Jackpot application, our APIs, and any related services (together, the
                <strong> "Services"</strong>). It also explains the rights that may be available to you.
            </LegalParagraph>

            <LegalParagraph>
                This Policy is part of, and is governed by, the Jackpot{' '}
                <a href="/terms" className="text-white hover:underline">Terms of Service</a>. Capitalized
                terms used but not defined have the meanings given to them in the Terms.
            </LegalParagraph>

            <nav className="my-10 rounded-xl border border-white/[0.06] bg-white/[0.02] p-5 text-sm text-white/55">
                <p className="mb-3 text-xs font-semibold uppercase tracking-[0.18em] text-white/45">Contents</p>
                <ol className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 list-decimal list-inside marker:text-white/35">
                    <li><a href="#s1" className="hover:text-white">Our Role</a></li>
                    <li><a href="#s2" className="hover:text-white">Information We Collect</a></li>
                    <li><a href="#s3" className="hover:text-white">How We Use Information</a></li>
                    <li><a href="#s4" className="hover:text-white">Legal Bases (EEA/UK)</a></li>
                    <li><a href="#s5" className="hover:text-white">How We Share Information</a></li>
                    <li><a href="#s6" className="hover:text-white">International Transfers</a></li>
                    <li><a href="#s7" className="hover:text-white">Retention</a></li>
                    <li><a href="#s8" className="hover:text-white">Your Rights</a></li>
                    <li><a href="#s9" className="hover:text-white">Cookies & Similar Technologies</a></li>
                    <li><a href="#s10" className="hover:text-white">Marketing Communications</a></li>
                    <li><a href="#s11" className="hover:text-white">AI Features</a></li>
                    <li><a href="#s12" className="hover:text-white">Children</a></li>
                    <li><a href="#s13" className="hover:text-white">Security</a></li>
                    <li><a href="#s14" className="hover:text-white">Region-Specific Disclosures</a></li>
                    <li><a href="#s15" className="hover:text-white">Changes to This Policy</a></li>
                    <li><a href="#s16" className="hover:text-white">Contact Us</a></li>
                </ol>
            </nav>

            <LegalSection id="s1" number="1" title="Our Role">
                <LegalParagraph>
                    Jackpot plays two roles depending on the data involved:
                </LegalParagraph>
                <LegalList
                    items={[
                        <><strong>Controller.</strong> For personal information we collect from website visitors, prospects, account-holders, and signed-in users for our own purposes — including account creation, authentication, billing, customer support, security, analytics, and marketing — Jackpot acts as the "controller" (or "business" under U.S. state privacy laws).</>,
                        <><strong>Processor.</strong> For personal information contained in Customer Content and processed on behalf of a business customer of Jackpot (for example, a brand or agency that uses the Services to manage its assets), Jackpot acts as the "processor" (or "service provider"). In that case, the Customer is the controller, the Customer's privacy notice applies to that personal information, and our processing is governed by our <a href="/dpa" className="text-white hover:underline">Data Processing Addendum</a>. If you are a data subject whose personal information was uploaded to Jackpot by a business customer, please direct rights requests to that business customer; we will support them as a processor.</>,
                    ]}
                />
            </LegalSection>

            <LegalSection id="s2" number="2" title="Information We Collect">
                <LegalParagraph>We collect the following categories of personal information:</LegalParagraph>
                <LegalList
                    items={[
                        <><strong>Account information:</strong> first name, last name, email address, password (stored as a salted hash — never in plain text), timezone, country, and optional profile details (address, city, state, postal code, avatar image).</>,
                        <><strong>Billing information:</strong> tokenized payment-method identifiers, last four digits of the payment card, billing address, Stripe customer and subscription identifiers, trial status, and invoice history. Full card numbers are processed by Stripe and are not stored by Jackpot.</>,
                        <><strong>Authentication and session data:</strong> login timestamps, IP address, user agent, session tokens, password-reset tokens, and (if enabled) multi-factor-authentication factors.</>,
                        <><strong>Usage and telemetry data:</strong> pages viewed, features used, clicks, requests, error traces, download events, and similar product analytics.</>,
                        <><strong>Device and log data:</strong> IP address, user agent, referrer URL, approximate location derived from IP, language, and device attributes.</>,
                        <><strong>Customer Content:</strong> assets, files, text, metadata, brand guidelines, comments, approval records, invitations, and any other content you upload or generate inside the Services.</>,
                        <><strong>Communications:</strong> messages you send to support, sales, or accessibility channels; replies you send to transactional or marketing email; survey responses; and any information contained therein.</>,
                        <><strong>Contact and lead data:</strong> information you submit through contact, sales, or newsletter forms, including name, email, phone number, company, role, message text, marketing-consent status, and UTM / referrer parameters.</>,
                        <><strong>Cookies and similar technologies:</strong> identifiers stored in cookies, local storage, and session storage. See Section 9.</>,
                    ]}
                />
                <LegalParagraph>
                    We collect information directly from you when you provide it, automatically when you
                    interact with the Services, and from third parties such as our payment processor, our
                    authentication and email-delivery providers, error-monitoring services, and publicly
                    available sources when you engage our sales team.
                </LegalParagraph>
                <LegalParagraph>
                    <strong>Sensitive information.</strong> We do not ask for, and we do not intend to
                    collect, special-category personal data (for example, health, biometric, genetic,
                    political, religious, ethnicity, sexual-orientation, or precise-location data) or
                    government-issued identifiers. Please do not upload or submit such information.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s3" number="3" title="How We Use Information">
                <LegalParagraph>We use personal information for the following purposes:</LegalParagraph>
                <LegalList
                    items={[
                        'to provide, operate, maintain, secure, and improve the Services, including authentication, session management, billing, rate limiting, error diagnosis, and feature delivery;',
                        'to process transactions and manage subscriptions, including payment processing through Stripe, invoicing, and collections;',
                        'to respond to support, sales, and accessibility inquiries;',
                        'to send transactional and service-related communications (e.g., account verification, password resets, billing notices, security alerts, policy changes);',
                        'to send marketing communications where permitted, with an always-available unsubscribe link (see Section 10);',
                        'to detect, investigate, and prevent fraud, abuse, security incidents, unauthorized access, and violations of our Terms;',
                        'to generate analytics, metrics, benchmarks, and insights about how the Services are used, including to improve existing features and to develop new ones;',
                        'to train, evaluate, and improve our own machine-learning and AI features, using anonymized and aggregated data as permitted by our Terms;',
                        'to comply with legal obligations, respond to lawful requests, and enforce our agreements and policies;',
                        'to establish, exercise, or defend legal claims;',
                        'for any other purpose disclosed at the point of collection or to which you consent.',
                    ]}
                />
                <LegalParagraph>
                    Our use of <strong>anonymized and aggregated data</strong> — data that cannot reasonably
                    be used to identify you — is not restricted by this Policy except as provided in our
                    Terms of Service, Section 9.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s4" number="4" title="Legal Bases (EEA / UK / Switzerland)">
                <LegalParagraph>
                    If the EU or UK General Data Protection Regulation applies to our processing, we rely on
                    the following legal bases:
                </LegalParagraph>
                <LegalList
                    items={[
                        <><strong>Contract.</strong> To provide the Services you request and perform our obligations under the Terms.</>,
                        <><strong>Legitimate interests.</strong> To secure, maintain, analyze, and improve the Services; to prevent fraud and abuse; to communicate with existing customers about our products (soft opt-in); and to defend our legal rights. You may object to this processing as described in Section 8.</>,
                        <><strong>Legal obligation.</strong> To meet tax, accounting, anti-fraud, and other compliance requirements.</>,
                        <><strong>Consent.</strong> For specific purposes where we ask for it, including non-essential cookies, marketing to prospects in jurisdictions that require opt-in, and any other purpose expressly conditioned on consent. You may withdraw consent at any time; withdrawal does not affect prior lawful processing.</>,
                        <><strong>Vital interests / public interest.</strong> In rare cases where necessary to protect life or comply with legal process.</>,
                    ]}
                />
            </LegalSection>

            <LegalSection id="s5" number="5" title="How We Share Information">
                <LegalParagraph>
                    We share personal information only as described below. <strong>We do not sell personal
                    information, we do not share personal information for cross-context behavioral advertising,
                    and we do not engage in "targeted advertising"</strong> as those terms are defined under
                    U.S. state privacy laws.
                </LegalParagraph>
                <LegalList
                    items={[
                        <><strong>Service providers (subprocessors).</strong> We share personal information with vendors that help us operate the Services under written agreements that require them to protect the information and use it only as instructed. Categories include cloud hosting and storage (Amazon Web Services), payment processing (Stripe), email delivery (Mailtrap, Postmark, Resend), push notifications (OneSignal), error monitoring (Sentry), and AI model providers (including, without limitation, OpenAI, Anthropic, Google, and Black Forest Labs). A current subprocessor list is maintained and may be updated from time to time.</>,
                        <><strong>Business customers.</strong> If you are an Authorized User on a Jackpot business account, we share information about your activity with the administrators of that account.</>,
                        <><strong>Professional advisors.</strong> Lawyers, accountants, insurers, and auditors, under confidentiality obligations.</>,
                        <><strong>Corporate transactions.</strong> In connection with a merger, acquisition, financing, reorganization, bankruptcy, receivership, sale of assets, or transition of service to another provider, we may share personal information with the counterparty, provided that the recipient is bound to honor commitments materially consistent with this Policy.</>,
                        <><strong>Legal and safety.</strong> To comply with law, respond to lawful requests from government authorities, enforce our agreements, protect the security, rights, property, or safety of Jackpot, our users, or others, and investigate fraud or wrongdoing.</>,
                        <><strong>With your consent or direction.</strong> Any other sharing you request or authorize.</>,
                    ]}
                />
            </LegalSection>

            <LegalSection id="s6" number="6" title="International Transfers">
                <LegalParagraph>
                    Jackpot is headquartered in the United States and hosts the Services primarily in the
                    United States. If you access the Services from, or provide personal information to us
                    from, outside the United States, your information will be transferred to, stored in, and
                    processed in the United States and in other countries where our subprocessors operate.
                    Data-protection laws in those countries may differ from the laws of your country.
                </LegalParagraph>
                <LegalParagraph>
                    Where personal information of individuals located in the European Economic Area, the
                    United Kingdom, or Switzerland is transferred to a country that has not been found to
                    provide an adequate level of protection, we rely on appropriate safeguards, including the
                    European Commission's Standard Contractual Clauses (and the UK International Data Transfer
                    Addendum or UK IDTA, as applicable). To request a copy of the clauses we have in place
                    with a particular subprocessor, contact us at{' '}
                    <a className="text-white hover:underline" href="mailto:privacy@jackpotbam.com">privacy@jackpotbam.com</a>.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s7" number="7" title="Retention">
                <LegalParagraph>
                    We keep personal information only for as long as is reasonably necessary for the purposes
                    described in this Policy and to meet our legal, accounting, audit, security,
                    fraud-prevention, and dispute-resolution obligations. Actual retention varies by data
                    category, by the status of your relationship with us, by the storage medium
                    (live systems, archival storage, backups), and by applicable law. We kindly ask that you
                    treat the periods below as general expectations rather than fixed promises.
                </LegalParagraph>
                <LegalList
                    items={[
                        <><strong>Customer Content</strong> — while the relevant account is active; once deleted by the customer through the Services, removed from live systems following the trash / grace-period mechanics described in the Services.</>,
                        <><strong>Account and profile information</strong> — while the account is active and for a reasonable period afterward to allow account recovery, dispute resolution, fraud-prevention review, and orderly closure, after which it is deleted or anonymized.</>,
                        <><strong>Billing, tax, and accounting records</strong> — as long as required by applicable tax, accounting, audit, and commercial-recordkeeping laws, which for U.S. federal and state purposes is typically several years.</>,
                        <><strong>Activity, audit, and security logs</strong> — for a period appropriate to investigate incidents, detect abuse, and satisfy legal holds, after which they are deleted, anonymized, or aggregated in the ordinary course.</>,
                        <><strong>Error logs, telemetry, and AI prompt / response logs</strong> — for a limited period appropriate to diagnose and improve the Services, after which they are deleted or anonymized.</>,
                        <><strong>Session records</strong> — for a limited period following the end of the session, unless retained for security investigation.</>,
                        <><strong>Marketing, lead, and contact-form data</strong> — until you unsubscribe or object, after which we may maintain a suppression list and limited records as needed to honor your opt-out.</>,
                        <><strong>Records of rights requests and consents</strong> — for a period appropriate to demonstrate compliance with applicable law.</>,
                    ]}
                />
                <LegalParagraph>
                    <strong>Backups and disaster recovery.</strong> Personal information that has been
                    deleted from our live production systems may remain in secure, encrypted rolling
                    backups and disaster-recovery copies for a limited period (generally not more than
                    approximately thirty (30) days, consistent with our cloud provider's standard backup
                    cycle) before it is overwritten in the ordinary course. During that period we treat
                    such information as <strong>"beyond use"</strong>: it is not used for any operational,
                    analytical, product-development, or AI-training purpose and is accessed only as
                    reasonably necessary for disaster recovery, audit, or where required by law or legal
                    process. We do not restore data from backups simply to respond to individual deletion
                    requests.
                </LegalParagraph>
                <LegalParagraph>
                    Anonymized and aggregated data — data that cannot reasonably be used to identify an
                    individual — may be kept indefinitely.
                </LegalParagraph>
                <LegalParagraph>
                    We are in the process of formalizing category-level retention schedules and automated
                    deletion workflows, and we will update this Policy as those schedules take effect.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s8" number="8" title="Your Rights">
                <LegalParagraph>
                    Depending on where you live and the laws that apply, you may have rights in relation to
                    your personal information, including the right to:
                </LegalParagraph>
                <LegalList
                    items={[
                        'access or obtain a copy of personal information we hold about you;',
                        'correct information that is inaccurate or incomplete;',
                        'delete personal information, subject to exceptions in applicable law (for example, records we must keep for tax or legal-defense purposes);',
                        'restrict or object to our processing, including processing based on legitimate interests;',
                        'receive your personal information in a portable, machine-readable format, or ask us to transmit it to another controller where technically feasible;',
                        'withdraw consent where processing is based on consent, without affecting the lawfulness of prior processing;',
                        'opt out of direct marketing at any time;',
                        'not be subject to a decision based solely on automated processing that produces legal or similarly significant effects — Jackpot does not engage in such decision-making with respect to individual users;',
                        'lodge a complaint with a supervisory authority in your jurisdiction (for EEA/UK residents), or contact your state attorney general (for U.S. state privacy laws).',
                    ]}
                />
                <LegalParagraph>
                    <strong>How to exercise your rights.</strong> To make a rights request, please contact
                    us at{' '}
                    <a className="text-white hover:underline" href="mailto:privacy@jackpotbam.com">privacy@jackpotbam.com</a>{' '}
                    with enough information for us to understand and verify your request. Account-holders
                    can also update much of their own profile information directly in the Services; an
                    in-product account-closure option may be available, but a request to fully exercise
                    your rights under this Section — including a deletion that extends beyond your own
                    profile row — should be made to us through the contact above so we can process it
                    end-to-end. If you submitted a contact, sales, or newsletter form using your email
                    before you had an account, you may{' '}
                    <a className="text-white hover:underline" href="/privacy/object-lead">
                        object to processing of that lead data here
                    </a>
                    .
                </LegalParagraph>
                <LegalParagraph>
                    <strong>Response window.</strong> We will acknowledge your request and, where required
                    by law, respond within <strong>thirty (30) days</strong>. We may extend the response
                    period where permitted by law (generally by up to an additional sixty (60) days for
                    complex or voluminous requests) and will tell you if we do and why.
                </LegalParagraph>
                <LegalParagraph>
                    <strong>How requests are handled.</strong> Rights requests are currently reviewed and
                    fulfilled by our team on a manual basis within the response window above. We are
                    investing in additional tooling to streamline fulfillment; until then, please use the
                    contact above rather than assume that a self-service action in the Services has
                    exercised a statutory right.
                </LegalParagraph>
                <LegalParagraph>
                    <strong>Verification.</strong> To protect you, we verify the identity of the person
                    making a request. If you are logged in, submitting the request from within your account
                    generally suffices. Otherwise, we will ask you to confirm the email address on file,
                    enter a one-time code we send to that address, and, where reasonable, provide
                    additional information that we already hold. We will not grant access to, or delete,
                    personal information based on a request we cannot reasonably verify.
                </LegalParagraph>
                <LegalParagraph>
                    <strong>Deletion is not absolute.</strong> We may refuse or limit a deletion request
                    where applicable law permits or requires us to retain information, including to
                    (a) complete a transaction for which the information was collected, (b) provide the
                    Services you have requested, (c) meet tax, accounting, audit, and recordkeeping
                    obligations, (d) detect or prevent fraud, abuse, or security incidents, (e) establish,
                    exercise, or defend legal claims, (f) comply with a legal hold, subpoena, court order,
                    or other legal process, (g) respect the exercise of free speech, or (h) use information
                    internally in a manner that is compatible with the context in which you provided it and
                    limited in scope. Where we cannot delete information in full, we will explain the
                    reason and, where appropriate, restrict its further use.
                </LegalParagraph>
                <LegalParagraph>
                    <strong>Authorized agents.</strong> Where applicable law permits, an authorized agent
                    may submit a request on your behalf with written, signed authorization and verification
                    of the agent's identity.
                </LegalParagraph>
                <LegalParagraph>
                    <strong>No retaliation.</strong> We will not discriminate against you for exercising
                    any of these rights.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s9" number="9" title="Cookies and Similar Technologies">
                <LegalParagraph>
                    We use cookies, local storage, and similar technologies to operate the Services, keep you
                    signed in, remember preferences, measure performance, and diagnose errors. We group these
                    into the following categories:
                </LegalParagraph>
                <LegalList
                    items={[
                        <><strong>Strictly necessary.</strong> Required for the Services to function — for example, authentication, CSRF protection, load balancing, and security. These cannot be disabled through the Services.</>,
                        <><strong>Functional.</strong> Remember preferences such as theme, language, and navigation state.</>,
                        <><strong>Analytics and performance.</strong> Help us understand usage and improve the Services. Where required by law, these are loaded only after you grant consent.</>,
                        <><strong>Marketing.</strong> Currently, we do not use advertising cookies or cross-site tracking for behavioral advertising.</>,
                    ]}
                />
                <LegalParagraph>
                    <strong>Consent for EEA, UK, and Switzerland visitors.</strong> For visitors located in
                    the EEA, UK, or Switzerland, non-essential cookies are loaded only after you grant consent
                    through our cookie banner. You can change or withdraw your consent at any time by
                    clearing our cookies or contacting us.
                </LegalParagraph>
                <LegalParagraph>
                    <strong>Notice for other visitors.</strong> For visitors in other jurisdictions, we
                    provide notice through this Policy and, where applicable, through our cookie banner.
                    Most browsers let you refuse or delete cookies through their settings; doing so may
                    affect the Services.
                </LegalParagraph>
                <LegalParagraph>
                    <strong>Do-Not-Track and Global Privacy Control.</strong> We currently do not respond to
                    Do-Not-Track signals. We honor the Global Privacy Control (GPC) signal as an opt-out of
                    "sale" and "sharing" of personal information for the applicable user session, to the
                    extent required by U.S. state privacy laws.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s10" number="10" title="Marketing Communications">
                <LegalParagraph>
                    With your permission where required, and on a legitimate-interest basis for existing and
                    prospective business customers, we may send you marketing and product updates. Every
                    marketing email contains an unsubscribe link, and you can opt out at any time by using
                    that link, by replying to the email, or by emailing{' '}
                    <a className="text-white hover:underline" href="mailto:privacy@jackpotbam.com">privacy@jackpotbam.com</a>.
                    Even after you unsubscribe from marketing, we will continue to send transactional and
                    service-related messages, such as security, billing, and policy notices.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s11" number="11" title="AI Features">
                <LegalParagraph>
                    The Services include AI-powered features, such as automated tagging, metadata
                    generation, image editing, brand research, and generative tools. When you use these
                    features, content you submit may be transmitted to our AI-model subprocessors for
                    processing. We enter into written agreements with those providers designed to prohibit
                    their use of your content to train their own models, except where you have opted in or
                    where otherwise permitted by the <a href="/dpa" className="text-white hover:underline">Data Processing Addendum</a>.
                    As permitted by our Terms, Jackpot may use anonymized and aggregated derivatives of
                    usage and content to train, evaluate, and improve Jackpot's own machine-learning and AI
                    features. AI output is probabilistic and may be inaccurate; please review it before
                    relying on it.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s12" number="12" title="Children">
                <LegalParagraph>
                    The Services are not directed to, and we do not knowingly collect personal information
                    from, anyone under the age of <strong>18</strong>. If you believe we have collected
                    personal information from a minor, please contact us at{' '}
                    <a className="text-white hover:underline" href="mailto:privacy@jackpotbam.com">privacy@jackpotbam.com</a>{' '}
                    and we will delete it.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s13" number="13" title="Security">
                <LegalParagraph>
                    We maintain technical and organizational measures designed to protect personal
                    information against unauthorized access, disclosure, alteration, and destruction. These
                    include encryption in transit, hashed passwords, role-based access controls, audit
                    logging, network segmentation with our cloud hosting provider, and employee access
                    governance. No method of transmission or storage is completely secure, and we cannot
                    guarantee absolute security. You are responsible for keeping your credentials
                    confidential and notifying us promptly of any suspected unauthorized use.
                </LegalParagraph>
                <LegalParagraph>
                    <strong>Notification.</strong> If we become aware of a personal-data breach that
                    requires notification under applicable law, we will notify the affected parties without
                    undue delay and as required by the law applicable to that breach.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s14" number="14" title="Region-Specific Disclosures">
                <h3 className="mt-6 text-white font-semibold">Individuals in the EEA, the UK, and Switzerland</h3>
                <LegalParagraph>
                    The "controller" is Jackpot Brand Asset Management, LLC at the address below. To
                    exercise your GDPR rights, contact us at{' '}
                    <a className="text-white hover:underline" href="mailto:privacy@jackpotbam.com">privacy@jackpotbam.com</a>.
                    You also have the right to lodge a complaint with your local data-protection
                    supervisory authority. We do not currently have an EU or UK representative appointed
                    under Article 27 of the GDPR; we will update this Policy if that changes.
                </LegalParagraph>

                <h3 className="mt-6 text-white font-semibold">California residents</h3>
                <LegalParagraph>
                    Under the California Consumer Privacy Act, as amended by the California Privacy Rights
                    Act ("CCPA/CPRA"), California residents have rights to know, access, correct, delete,
                    limit use of sensitive personal information, and opt out of "sale" and "sharing." We
                    describe the categories of personal information we collect in Section 2 and the
                    purposes in Section 3. <strong>We do not sell personal information, we do not share
                    personal information for cross-context behavioral advertising, and we have not done so
                    in the preceding 12 months.</strong> We do not knowingly collect or sell the personal
                    information of consumers under 16 without the consent required by law. To exercise
                    California rights, use the contacts in Section 16.
                </LegalParagraph>

                <h3 className="mt-6 text-white font-semibold">Other U.S. state privacy laws</h3>
                <LegalParagraph>
                    Residents of states with comprehensive consumer privacy laws — including, as of the
                    effective date of this Policy, Virginia, Colorado, Connecticut, Utah, Texas, Oregon,
                    Montana, Iowa, Delaware, Tennessee, Indiana, New Jersey, and others — have rights
                    similar to those described above. We do not engage in "targeted advertising," the
                    "sale" of personal information, or "profiling" that produces legal or similarly
                    significant effects, as those terms are defined in those laws. To exercise your rights,
                    use the contacts in Section 16.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s15" number="15" title="Changes to This Policy">
                <LegalParagraph>
                    We may update this Policy from time to time. When we make material changes, we will
                    post the updated Policy with a new effective date and provide reasonable notice (for
                    example, by email to account-holders or by in-product notice). Non-material changes
                    take effect on posting. Your continued use of the Services after the effective date
                    constitutes acceptance of the updated Policy.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s16" number="16" title="Contact Us">
                <LegalParagraph>
                    If you have questions about this Policy or our privacy practices, or if you wish to
                    exercise a right described in Section 8, contact us:
                </LegalParagraph>
                <div className="mt-4 rounded-xl border border-white/[0.06] bg-white/[0.02] p-5 text-sm text-white/65">
                    <p className="font-semibold text-white">Jackpot Brand Asset Management, LLC</p>
                    <p className="mt-1">An Ohio limited liability company</p>
                    <p className="mt-3">
                        100 Pheasant Woods Ct<br />
                        Loveland, OH 45140<br />
                        United States
                    </p>
                    <p className="mt-3">
                        Privacy inquiries:{' '}
                        <a className="text-white hover:underline" href="mailto:privacy@jackpotbam.com">privacy@jackpotbam.com</a>
                    </p>
                    <p>
                        General inquiries:{' '}
                        <a className="text-white hover:underline" href="mailto:support@jackpotbam.com">support@jackpotbam.com</a>
                    </p>
                    <p>
                        Legal notices:{' '}
                        <a className="text-white hover:underline" href="mailto:legal@jackpotbam.com">legal@jackpotbam.com</a>
                    </p>
                </div>
            </LegalSection>
        </LegalPage>
    )
}
