import LegalPage, { LegalSection, LegalParagraph, LegalList } from '../../Components/Legal/LegalPage'

/**
 * Terms of Service for Jackpot Brand Asset Management, LLC.
 *
 * Drafting posture:
 * - Governing law: Ohio. Venue / arbitration seat: Franklin County, Ohio.
 * - Dispute resolution: binding AAA arbitration with class-action waiver.
 * - Minimum age: 18.
 * - AI credit policy: monthly forfeit, no cash value; discretionary vs subscription fees;
 *   no refund/re-credit obligation; program terms may change without subscription-fee notice.
 * - Price-change notice: 30 days before renewal for subscription base fees; AI Credit program
 *   (allocations, burn rates, worth) may change at any time as described in Section 7.
 * - Content license: Customer retains ownership; grants Jackpot broad operational license.
 * - Anonymized / aggregated data: Jackpot may use for service improvement, analytics,
 *   benchmarking, and development of machine-learning/AI features. Jackpot will not use
 *   identifiable personal data or Customer Confidential Information for cross-customer
 *   external model training except as permitted by the Privacy Policy or DPA.
 * - Trademark: ™ pending.
 * - Audience: B2B primary; individual/consumer accounts allowed.
 *
 * This file is counsel-ready draft copy, not a substitute for legal review.
 */
export default function Terms() {
    return (
        <LegalPage title="Terms of Service" effectiveDate="May 3, 2026">
            <LegalParagraph>
                These Terms of Service (the <strong>"Terms"</strong>) form a binding legal agreement between{' '}
                <strong>Jackpot Brand Asset Management, LLC</strong>, an Ohio limited liability company doing
                business as <strong>Jackpot™</strong> ("Jackpot," "we," "us," or "our"), and the individual or
                legal entity that accesses or uses the Services ("Customer," "you," or "your"). By creating an
                account, accessing, or using the Services, you agree to be bound by these Terms. If you do not
                agree, you must not access or use the Services.
            </LegalParagraph>

            <LegalParagraph>
                <strong>Important:</strong> Section 18 (Binding Arbitration; Class-Action Waiver) requires that
                most disputes be resolved by individual binding arbitration and waives your right to bring or
                participate in a class action. Please read it carefully.
            </LegalParagraph>

            <nav className="my-10 rounded-xl border border-white/[0.06] bg-white/[0.02] p-5 text-sm text-white/55">
                <p className="mb-3 text-xs font-semibold uppercase tracking-[0.18em] text-white/45">Contents</p>
                <ol className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 list-decimal list-inside marker:text-white/35">
                    <li><a href="#s1" className="hover:text-white">Definitions</a></li>
                    <li><a href="#s2" className="hover:text-white">Eligibility</a></li>
                    <li><a href="#s3" className="hover:text-white">Accounts & Security</a></li>
                    <li><a href="#s4" className="hover:text-white">The Services</a></li>
                    <li><a href="#s5" className="hover:text-white">Subscriptions, Fees & Renewal</a></li>
                    <li><a href="#s6" className="hover:text-white">AI Credits</a></li>
                    <li><a href="#s7" className="hover:text-white">Price Changes; AI Credit Program</a></li>
                    <li><a href="#s8" className="hover:text-white">Customer Content & License</a></li>
                    <li><a href="#s9" className="hover:text-white">Anonymized & Aggregated Data</a></li>
                    <li><a href="#s10" className="hover:text-white">Acceptable Use</a></li>
                    <li><a href="#s11" className="hover:text-white">Intellectual Property</a></li>
                    <li><a href="#s12" className="hover:text-white">Third-Party Services</a></li>
                    <li><a href="#s13" className="hover:text-white">Confidentiality</a></li>
                    <li><a href="#s14" className="hover:text-white">Privacy & Data Protection</a></li>
                    <li><a href="#s15" className="hover:text-white">Warranties & Disclaimers</a></li>
                    <li><a href="#s16" className="hover:text-white">Limitation of Liability</a></li>
                    <li><a href="#s17" className="hover:text-white">Indemnification</a></li>
                    <li><a href="#s18" className="hover:text-white">Arbitration; Class Waiver</a></li>
                    <li><a href="#s19" className="hover:text-white">Governing Law</a></li>
                    <li><a href="#s20" className="hover:text-white">Term; Suspension; Termination</a></li>
                    <li><a href="#s21" className="hover:text-white">Beta & Early-Access Features</a></li>
                    <li><a href="#s22" className="hover:text-white">Export & Sanctions</a></li>
                    <li><a href="#s23" className="hover:text-white">Force Majeure</a></li>
                    <li><a href="#s24" className="hover:text-white">Changes to These Terms</a></li>
                    <li><a href="#s25" className="hover:text-white">Notices</a></li>
                    <li><a href="#s26" className="hover:text-white">General Provisions</a></li>
                </ol>
            </nav>

            <LegalSection id="s1" number="1" title="Definitions">
                <LegalParagraph>
                    Capitalized terms have the meanings set forth below. Additional defined terms may appear in
                    context.
                </LegalParagraph>
                <LegalList
                    items={[
                        <><strong>"Account"</strong> means the Customer-owned credentials and workspace through which Customer and its Authorized Users access the Services.</>,
                        <><strong>"Authorized User"</strong> means an individual whom Customer permits to use the Services under Customer's Account.</>,
                        <><strong>"AI Credits"</strong> means the pre-paid or subscription-allotted units that Customer may redeem to invoke AI-powered features within the Services.</>,
                        <><strong>"Customer Content"</strong> means any data, files, images, videos, text, trademarks, logos, brand guidelines, metadata, or other materials that Customer or its Authorized Users upload to, create within, or submit through the Services.</>,
                        <><strong>"Documentation"</strong> means the user guides, technical specifications, and policies made available by Jackpot for the Services.</>,
                        <><strong>"Order Form"</strong> means any order, subscription selection, or online checkout flow through which Customer subscribes to the Services.</>,
                        <><strong>"Services"</strong> means the Jackpot web application, APIs, AI-powered features, mobile interfaces, integrations, and any related software, updates, and Documentation made available by Jackpot at <a className="text-white hover:underline" href="https://jackpotbam.com">jackpotbam.com</a> or any successor domain.</>,
                        <><strong>"Subscription Term"</strong> means the period during which Customer is authorized to access the Services under the plan selected by Customer.</>,
                        <><strong>"Usage Data"</strong> means telemetry, logs, metrics, and other technical information generated by Customer's and Authorized Users' interactions with the Services.</>,
                    ]}
                />
            </LegalSection>

            <LegalSection id="s2" number="2" title="Eligibility">
                <LegalParagraph>
                    You must be at least <strong>18 years old</strong> and capable of forming a legally binding
                    contract to use the Services. If you accept these Terms on behalf of a company or other legal
                    entity, you represent that you have authority to bind that entity, in which case "Customer"
                    refers to that entity. The Services are intended primarily for business use. Jackpot may, in
                    its sole discretion, refuse service, terminate Accounts, or cancel orders.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s3" number="3" title="Accounts and Security">
                <LegalParagraph>
                    Customer is responsible for (a) maintaining the confidentiality of Account credentials,
                    (b) all activities that occur under its Account, whether authorized or not, (c) ensuring that
                    Authorized Users comply with these Terms, and (d) promptly notifying Jackpot of any suspected
                    unauthorized access or use. Jackpot may require, and may from time to time require, additional
                    authentication mechanisms (including multi-factor authentication) and may disable credentials
                    that it reasonably believes have been compromised.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s4" number="4" title="The Services">
                <LegalParagraph>
                    Subject to these Terms, Jackpot grants Customer a limited, non-exclusive, non-transferable,
                    non-sublicensable, revocable right during the Subscription Term to access and use the Services
                    for Customer's internal business purposes. Jackpot may modify, add, remove, or discontinue
                    features, functionality, integrations, or any portion of the Services at any time, with or
                    without notice. Jackpot will use commercially reasonable efforts to provide continuity of core
                    functionality but makes no guarantee that any particular feature will remain available.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s5" number="5" title="Subscriptions, Fees, and Renewal">
                <LegalList
                    ordered
                    items={[
                        <><strong>Fees.</strong> Customer will pay all fees specified in the applicable Order Form or plan selection, in U.S. dollars, in advance, without setoff or deduction. All fees are non-refundable except as expressly stated in these Terms.</>,
                        <><strong>Billing.</strong> Customer authorizes Jackpot and its payment processor (Stripe) to charge the payment method on file for all recurring and usage-based fees. If a charge is declined, Jackpot may suspend the Services until payment is received and may assess late fees and reasonable collection costs.</>,
                        <><strong>Renewal.</strong> Subscriptions renew automatically for successive periods equal to the then-current Subscription Term unless Customer cancels through the Services or in writing to <a className="text-white" href="mailto:support@jackpotbam.com">support@jackpotbam.com</a> at least fifteen (15) days before the end of the current term.</>,
                        <><strong>Taxes.</strong> Fees are exclusive of all applicable taxes. Customer is responsible for all taxes other than taxes on Jackpot's net income.</>,
                        <><strong>No Refunds.</strong> Except where required by applicable law, Jackpot does not provide refunds or credits for partial periods, unused portions of a Subscription Term, unused AI Credits, or features Customer does not use. Without limiting the foregoing, Jackpot has no obligation to refund subscription fees, to restore or re-issue AI Credits, or to grant monetary or account credit for any dispute, outage, metering issue, or dissatisfaction relating to AI Credits or AI-powered features.</>,
                        <><strong>Collections.</strong> Past-due amounts accrue interest at the lesser of 1.5% per month or the maximum permitted by law. Customer will reimburse Jackpot for all reasonable costs of collection, including attorneys' fees.</>,
                    ]}
                />
            </LegalSection>

            <LegalSection id="s6" number="6" title="AI Credits">
                <LegalList
                    ordered
                    items={[
                        <><strong>Nature.</strong> AI Credits are a limited, revocable, non-transferable license to invoke AI-powered Services. AI Credits have no cash value, are not property, are not a security, and cannot be redeemed, exchanged, transferred, or sold.</>,
                        <><strong>Not Part of Subscription Consideration.</strong> Any AI Credits granted in connection with a plan—including complimentary, promotional, or plan-included allocations—are a <strong>discretionary benefit</strong>. They do not form part of the bargained-for consideration for recurring subscription fees, which principally secure access to the licensed Services. The presence, absence, or level of AI Credit allocations does not reduce, offset, or otherwise affect the amount or lawfulness of subscription fees except as Jackpot expressly states in an Order Form.</>,
                        <><strong>No Refund, Restoration, or Re-Credit.</strong> Except where required by applicable law, AI Credits are <strong>never refundable</strong> in cash or as a credit against fees. Jackpot has <strong>no obligation</strong> to restore, replace, re-credit, or otherwise compensate Customer for AI Credits that are lost, expired, forfeited, consumed, allegedly consumed in error, or affected by outages, degradation, third-party model or API failures, security incidents, bugs, misconfiguration, or any other circumstance, whether or not attributable to Jackpot. Any discretionary adjustment Jackpot may make in an individual case creates <strong>no precedent or ongoing obligation</strong>.</>,
                        <><strong>Monthly Forfeiture.</strong> Unless an Order Form expressly states otherwise, unused AI Credits <strong>expire and are forfeited at the end of each billing period</strong> and do not roll over.</>,
                        <><strong>Forfeiture on Termination.</strong> All AI Credits are immediately forfeited upon cancellation, downgrade, suspension, or termination of the Account, for any reason.</>,
                        <><strong>Consumption.</strong> Jackpot determines in good faith the number of AI Credits consumed by a given action. Credit costs may vary by model, input size, output size, region, and feature, and may change from time to time.</>,
                        <><strong>No Guarantee.</strong> AI features are non-deterministic and dependent on third-party models. Jackpot makes no warranty as to accuracy, suitability, availability, or quality of AI-generated output. Customer is solely responsible for reviewing AI output before relying on it.</>,
                        <><strong>Overage.</strong> If Customer exceeds its plan allowance, Jackpot may (a) charge usage-based overage fees at the then-current rate, (b) throttle or suspend AI features, or (c) both.</>,
                    ]}
                />
            </LegalSection>

            <LegalSection id="s7" number="7" title="Price Changes; AI Credit Program">
                <LegalParagraph>
                    Jackpot may change <strong>recurring subscription fees</strong>, credit-pack pricing, overage
                    rates, or the composition of any plan. For recurring subscription fees, Jackpot will provide
                    Customer at least <strong>thirty (30) days' written notice</strong> (by email to the Account
                    contact or by in-product notice) prior to the change taking effect at the next renewal. If
                    Customer does not agree to a subscription fee change, Customer's sole remedy is to cancel
                    before the change becomes effective; continued use after the effective date constitutes
                    acceptance.
                </LegalParagraph>
                <LegalParagraph>
                    <strong>AI Credit program.</strong> Independently of the notice period above, Jackpot may at
                    any time, with or without prior notice, modify (i) the number or frequency of AI Credits
                    allocated to any plan or Customer, (ii) the number of AI Credits consumed by any action,
                    model, or feature, (iii) eligibility of any feature for AI Credit consumption, or (iv) any
                    methodology or weighting used to determine consumption or the relative economic "worth" of a
                    credit as between features—effective immediately or on a date Jackpot specifies in-product or
                    by email. Such changes are <strong>not</strong> increases to the recurring subscription base
                    fee and need not follow the thirty (30) day notice cycle in the first paragraph of this
                    Section, though Jackpot may align changes with a billing period where practicable. For
                    separately purchased credit packs or metered overage <strong>rates</strong> (priced in
                    currency), changes take effect at the start of the next billing period following notice unless
                    an Order Form states otherwise.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s8" number="8" title="Customer Content and License">
                <LegalList
                    ordered
                    items={[
                        <><strong>Ownership.</strong> As between the parties, Customer retains all right, title, and interest in and to Customer Content, subject to the license granted below.</>,
                        <><strong>License to Jackpot.</strong> Customer grants Jackpot a worldwide, non-exclusive, royalty-free, fully paid-up, sublicensable (to Jackpot's subprocessors) license to host, copy, transmit, display, process, index, analyze, generate derivative works from, and otherwise use Customer Content to (i) provide, maintain, and improve the Services, (ii) perform AI-powered processing requested by Customer, (iii) enforce these Terms and the Acceptable Use policy, and (iv) comply with law.</>,
                        <><strong>Representations.</strong> Customer represents and warrants that (i) it has all rights, licenses, consents, and permissions necessary to upload Customer Content and grant the licenses in these Terms, and (ii) Customer Content, and Jackpot's use of it as authorized, will not infringe, misappropriate, or violate any third-party right or applicable law.</>,
                        <><strong>Deletion & Retention.</strong> Customer may delete Customer Content through the Services. Jackpot may retain backup copies, audit logs, and residual data in the ordinary course of business and as required by law, and may retain anonymized or aggregated derivatives as described in Section 9.</>,
                        <><strong>Feedback.</strong> If Customer provides suggestions, ideas, enhancement requests, or other feedback, Customer grants Jackpot a perpetual, irrevocable, worldwide, royalty-free license to use and incorporate such feedback without restriction and without obligation of any kind.</>,
                    ]}
                />
            </LegalSection>

            <LegalSection id="s9" number="9" title="Anonymized and Aggregated Data">
                <LegalParagraph>
                    Jackpot may collect, generate, and derive anonymized and/or aggregated data from Customer's
                    use of the Services, including Usage Data and statistical or technical information derived
                    from Customer Content. Jackpot may use such anonymized and aggregated data for (a) operating,
                    analyzing, maintaining, securing, and improving the Services, (b) product analytics,
                    performance measurement, and internal benchmarking, (c) developing, training, evaluating, and
                    improving Jackpot's own machine-learning and AI features, and (d) producing industry reports
                    and insights, provided that such reports do not identify Customer or any individual.
                </LegalParagraph>
                <LegalParagraph>
                    Jackpot will <strong>not</strong> use Customer Confidential Information or data that directly
                    identifies an individual ("personal data") for training external, cross-customer AI models
                    except (i) as permitted by the Jackpot Privacy Policy or an applicable Data Processing
                    Addendum, (ii) with the affected party's consent, or (iii) as required by law. Anonymized and
                    aggregated data does not constitute personal data or Customer Confidential Information and is
                    owned by Jackpot.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s10" number="10" title="Acceptable Use">
                <LegalParagraph>Customer and its Authorized Users will not, and will not permit any third party to:</LegalParagraph>
                <LegalList
                    items={[
                        'violate any applicable law, regulation, or third-party right;',
                        'upload, transmit, or generate content that is unlawful, defamatory, obscene, pornographic (including any content depicting minors), hateful, harassing, or that infringes intellectual property or privacy rights;',
                        'upload malware, or use the Services to probe, scan, or test the vulnerability of any system or network;',
                        'attempt to gain unauthorized access to the Services, other accounts, or underlying infrastructure;',
                        'reverse engineer, decompile, disassemble, or attempt to derive the source code of the Services, except where applicable law expressly permits;',
                        'use the Services to build or improve a competing product or service, or to benchmark or evaluate the Services for a competitor;',
                        'resell, rent, lease, sublicense, or otherwise make the Services available to third parties except as expressly authorized by Jackpot;',
                        'circumvent or disable any rate limit, access control, quota, credit limit, digital rights management, or security feature;',
                        'use the Services in connection with the development, design, manufacture, or production of weapons of mass destruction or to support any activity subject to U.S. export restrictions;',
                        'use AI features to generate content that impersonates a real person without authorization, facilitates fraud, produces non-consensual sexual material, or produces material designed to mislead voters or interfere with elections;',
                        'upload data types that Jackpot expressly prohibits, including protected health information, payment-card primary account numbers, government-issued identifiers, credentials, or special-category personal data, except under a written agreement expressly permitting such use.',
                    ]}
                />
                <LegalParagraph>
                    Jackpot may investigate suspected violations and may remove content, suspend the Account, or
                    terminate the Services in accordance with Section 20.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s11" number="11" title="Intellectual Property">
                <LegalParagraph>
                    The Services, including all software, models, algorithms, interfaces, designs, text, graphics,
                    logos, and the selection, coordination, and arrangement thereof, are owned by Jackpot or its
                    licensors and are protected by U.S. and international intellectual property and proprietary
                    laws. Except for the limited access right granted in Section 4, no rights are granted by
                    implication, estoppel, or otherwise. <strong>Jackpot</strong> and the Jackpot logo are
                    trademarks of Jackpot Brand Asset Management, LLC (registrations pending). All other marks
                    are the property of their respective owners.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s12" number="12" title="Third-Party Services">
                <LegalParagraph>
                    The Services integrate with third-party services, including payment processing (Stripe),
                    hosting and storage (Amazon Web Services), AI model providers (including, without limitation,
                    OpenAI, Anthropic, Google, and others), push and email delivery, and monitoring vendors.
                    Customer's use of those services is subject to their respective terms. Jackpot does not
                    control and is not responsible for third-party services, and their availability, performance,
                    or policies may change at any time. Jackpot may add, remove, or change third-party providers
                    in its discretion, subject to any notice obligations set forth in the Data Processing
                    Addendum.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s13" number="13" title="Confidentiality">
                <LegalParagraph>
                    Each party (the "Receiving Party") will protect the other's non-public information
                    ("Confidential Information") with the same degree of care it uses for its own confidential
                    information, and in no event less than a reasonable degree of care. Confidential Information
                    does not include information that is (a) publicly available without breach, (b) known to the
                    Receiving Party without confidentiality obligation before disclosure, (c) independently
                    developed without use of the other's Confidential Information, or (d) received from a third
                    party without confidentiality obligation. The Receiving Party may disclose Confidential
                    Information as required by law provided it gives the other party, where legally permitted,
                    reasonable advance notice.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s14" number="14" title="Privacy and Data Protection">
                <LegalParagraph>
                    Jackpot's collection and use of personal data is described in the{' '}
                    <a href="/privacy" className="text-white hover:underline">Privacy Policy</a>, which is
                    incorporated into these Terms. To the extent Jackpot processes personal data on Customer's
                    behalf in Customer's capacity as a data controller or business, the{' '}
                    <a href="/dpa" className="text-white hover:underline">Data Processing Addendum</a> applies and
                    is hereby incorporated. In the event of conflict with these Terms as to the processing of
                    personal data, the Data Processing Addendum controls.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s15" number="15" title="Warranties and Disclaimers">
                <LegalParagraph>
                    THE SERVICES, INCLUDING ANY AI-GENERATED OUTPUT AND ANY BETA OR EARLY-ACCESS FEATURES, ARE
                    PROVIDED <strong>"AS IS"</strong> AND <strong>"AS AVAILABLE"</strong> WITHOUT WARRANTY OF ANY
                    KIND. TO THE FULLEST EXTENT PERMITTED BY LAW, JACKPOT AND ITS AFFILIATES, LICENSORS, AND
                    SUPPLIERS DISCLAIM ALL WARRANTIES, EXPRESS, IMPLIED, OR STATUTORY, INCLUDING WARRANTIES OF
                    MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, TITLE, NON-INFRINGEMENT, ACCURACY, QUIET
                    ENJOYMENT, AND ANY WARRANTY ARISING FROM COURSE OF DEALING OR USAGE OF TRADE.
                </LegalParagraph>
                <LegalParagraph>
                    JACKPOT DOES NOT WARRANT THAT THE SERVICES WILL BE UNINTERRUPTED, ERROR-FREE, SECURE, OR FREE
                    OF HARMFUL COMPONENTS, THAT DATA WILL NOT BE LOST OR ALTERED, THAT AI-GENERATED OUTPUT WILL BE
                    ACCURATE OR SUITABLE FOR ANY PURPOSE, OR THAT THE SERVICES WILL MEET ANY SPECIFIC PERFORMANCE
                    OR AVAILABILITY OBJECTIVE EXCEPT AS EXPRESSLY STATED IN A WRITTEN SLA EXECUTED BY JACKPOT.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s16" number="16" title="Limitation of Liability">
                <LegalParagraph>
                    TO THE FULLEST EXTENT PERMITTED BY LAW, IN NO EVENT WILL JACKPOT OR ITS AFFILIATES, OFFICERS,
                    DIRECTORS, EMPLOYEES, AGENTS, LICENSORS, OR SUPPLIERS BE LIABLE FOR ANY INDIRECT, INCIDENTAL,
                    SPECIAL, CONSEQUENTIAL, EXEMPLARY, OR PUNITIVE DAMAGES; LOST PROFITS; LOST REVENUE; LOSS OF
                    GOODWILL; LOSS OF DATA OR CONTENT; BUSINESS INTERRUPTION; OR THE COST OF SUBSTITUTE SERVICES,
                    HOWEVER CAUSED, WHETHER IN CONTRACT, TORT, STRICT LIABILITY, OR OTHERWISE, EVEN IF ADVISED OF
                    THE POSSIBILITY OF SUCH DAMAGES.
                </LegalParagraph>
                <LegalParagraph>
                    THE TOTAL AGGREGATE LIABILITY OF JACKPOT AND ITS AFFILIATES UNDER OR RELATED TO THESE TERMS OR
                    THE SERVICES WILL NOT EXCEED THE <strong>GREATER OF (A) ONE HUNDRED U.S. DOLLARS ($100) OR (B)
                    THE AMOUNTS ACTUALLY PAID BY CUSTOMER TO JACKPOT FOR THE SERVICES DURING THE TWELVE (12) MONTHS
                    IMMEDIATELY PRECEDING THE EVENT GIVING RISE TO LIABILITY</strong>. THE EXISTENCE OF MULTIPLE
                    CLAIMS WILL NOT EXPAND THIS LIMIT. THESE LIMITATIONS APPLY TO THE MAXIMUM EXTENT PERMITTED BY
                    LAW EVEN IF ANY LIMITED REMEDY FAILS OF ITS ESSENTIAL PURPOSE.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s17" number="17" title="Indemnification">
                <LegalParagraph>
                    Customer will defend, indemnify, and hold harmless Jackpot and its affiliates, officers,
                    directors, employees, and agents from and against any third-party claims, damages, losses,
                    liabilities, costs, and expenses (including reasonable attorneys' fees) arising from or
                    related to (a) Customer Content, (b) Customer's or its Authorized Users' use of the Services
                    in violation of these Terms or applicable law, (c) Customer's violation of any third-party
                    right, (d) any dispute between Customer and its Authorized Users or end customers, and
                    (e) Customer's use of AI-generated output.
                </LegalParagraph>
                <LegalParagraph>
                    Jackpot will defend Customer from any third-party claim alleging that the Services, when used
                    by Customer in accordance with these Terms, infringe that third party's U.S. patent,
                    copyright, or trademark, and will pay damages finally awarded or agreed in settlement,
                    provided that Customer (i) promptly notifies Jackpot in writing, (ii) gives Jackpot sole
                    control of defense and settlement, and (iii) provides reasonable cooperation. Jackpot has no
                    obligation for claims arising from (w) Customer Content, (x) combination of the Services with
                    other products or services not provided by Jackpot, (y) modifications not made by Jackpot, or
                    (z) use after notice to stop. This Section states Jackpot's sole liability and Customer's
                    sole remedy for intellectual-property infringement claims.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s18" number="18" title="Binding Arbitration; Class-Action Waiver">
                <LegalParagraph>
                    <strong>Please read this Section carefully. It affects your legal rights.</strong>
                </LegalParagraph>
                <LegalList
                    ordered
                    items={[
                        <><strong>Agreement to Arbitrate.</strong> Any dispute, claim, or controversy arising out of or relating to these Terms or the Services, including the validity, scope, or enforceability of this arbitration agreement, will be resolved exclusively by final and binding individual arbitration administered by the American Arbitration Association (AAA) under its Commercial Arbitration Rules or, if applicable, Consumer Arbitration Rules. The arbitration will be conducted by a single arbitrator in Franklin County, Ohio, or, at the claimant's election, by video or telephone. Judgment on the award may be entered in any court of competent jurisdiction.</>,
                        <><strong>Class-Action Waiver.</strong> You and Jackpot each waive any right to have any dispute resolved in a class, collective, consolidated, or representative proceeding. The arbitrator may award relief only on an individual basis and may not award class or representative relief. If this waiver is found unenforceable as to any claim, that claim must be severed and litigated in court, and the remainder will proceed in arbitration.</>,
                        <><strong>Exceptions.</strong> Either party may (i) bring an individual action in small-claims court for disputes within its jurisdiction, or (ii) seek injunctive or equitable relief in court to prevent actual or threatened infringement, misappropriation, or violation of intellectual property, confidentiality, or account-security rights.</>,
                        <><strong>Opt-Out.</strong> A Customer who is a natural person may opt out of this arbitration agreement by sending written notice to <a className="text-white" href="mailto:legal@jackpotbam.com">legal@jackpotbam.com</a> within thirty (30) days of first accepting these Terms. The notice must state the Customer's name, Account email, and an unambiguous statement that the Customer opts out of arbitration.</>,
                        <><strong>Costs.</strong> Each party bears its own fees and costs except as the AAA Rules or applicable law otherwise require.</>,
                    ]}
                />
            </LegalSection>

            <LegalSection id="s19" number="19" title="Governing Law and Venue">
                <LegalParagraph>
                    These Terms are governed by the laws of the State of <strong>Ohio</strong>, without regard to
                    its conflict-of-laws principles, and by applicable U.S. federal law. The U.N. Convention on
                    Contracts for the International Sale of Goods does not apply. Subject to Section 18, the
                    state and federal courts located in Franklin County, Ohio have exclusive jurisdiction over
                    any matter not subject to arbitration, and each party consents to personal jurisdiction and
                    venue there.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s20" number="20" title="Term; Suspension; Termination">
                <LegalList
                    ordered
                    items={[
                        <><strong>Term.</strong> These Terms apply from Customer's first use of the Services until the Account is terminated.</>,
                        <><strong>Suspension.</strong> Jackpot may immediately suspend the Account or any portion of the Services if (i) Customer fails to pay any amount when due, (ii) Jackpot reasonably believes Customer is violating these Terms or creating a security, legal, or reputational risk, or (iii) required by law or third-party provider.</>,
                        <><strong>Termination for Convenience.</strong> Customer may terminate for convenience by canceling through the Services; cancellation takes effect at the end of the then-current billing period and does not entitle Customer to a refund.</>,
                        <><strong>Termination for Cause.</strong> Jackpot may terminate immediately on written notice for material breach, repeated violations of Acceptable Use, insolvency of Customer, fraud, or use of the Services that exposes Jackpot to legal or security risk.</>,
                        <><strong>Effect.</strong> On termination, Customer's right to access the Services ends immediately. Jackpot may, but is not obligated to, make Customer Content available for export for up to thirty (30) days, after which Jackpot may delete or anonymize Customer Content consistent with its retention schedule. AI Credits, unused subscription portions, and customizations are forfeited.</>,
                        <><strong>Survival.</strong> Sections 1, 5–9, 11, 13, 15–19, 20(e)–(f), and 26 survive termination.</>,
                    ]}
                />
            </LegalSection>

            <LegalSection id="s21" number="21" title="Beta and Early-Access Features">
                <LegalParagraph>
                    Jackpot may make features, modules, or integrations available as "beta," "preview," "alpha,"
                    or "early access" (<strong>"Beta Features"</strong>). Beta Features are provided solely as an{' '}
                    <strong>ancillary, discretionary convenience</strong> and do <strong>not</strong> form part of
                    the core Services or any committed functionality under these Terms, an Order Form, or the
                    Documentation unless Jackpot expressly designates a feature as generally available in a
                    written release notice. Customer has <strong>no contractual expectation</strong> that Beta
                    Features will be provided, will remain available, will achieve any particular quality or
                    performance, or will ever graduate to a generally available product.
                </LegalParagraph>
                <LegalParagraph>
                    Beta Features are offered for evaluation only, without any warranty or support obligation (and
                    may be supported on a best-efforts basis only), may change or be discontinued at any time with
                    or without notice, and are excluded from any service-level agreement or uptime commitment.
                    Section 15 (Disclaimers) and Section 16 (Limitation of Liability) apply to Beta Features
                    without exception. Customer's use of Beta Features is at Customer's sole risk.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s22" number="22" title="Export and Sanctions">
                <LegalParagraph>
                    Customer represents that it is not located in, and is not a national or resident of, any
                    country subject to U.S. embargo, and is not identified on any U.S. government list of
                    restricted or prohibited parties. Customer will comply with all applicable U.S. and
                    international export-control and sanctions laws and will not export, re-export, or transfer
                    the Services in violation of those laws.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s23" number="23" title="Force Majeure">
                <LegalParagraph>
                    Neither party is liable for any failure or delay in performance (other than payment
                    obligations) due to causes beyond its reasonable control, including acts of God, war,
                    terrorism, civil unrest, labor disputes, epidemics, pandemics, governmental action, network
                    or utility failure, third-party service outages, denial-of-service attacks, or malicious code
                    injected by third parties.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s24" number="24" title="Changes to These Terms">
                <LegalParagraph>
                    Jackpot may modify these Terms at any time. For material changes, Jackpot will provide
                    reasonable notice (by email, in-product notice, or posting with an updated effective date) at
                    least thirty (30) days before the change takes effect. Non-material changes take effect on
                    posting. Continued use of the Services after the effective date constitutes acceptance of the
                    revised Terms. If Customer does not agree, Customer's sole remedy is to stop using the
                    Services and cancel its subscription before the effective date.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s25" number="25" title="Notices">
                <LegalParagraph>
                    Jackpot may give notice by email to the Account contact, by posting in the Services, or by
                    other reasonable means. Customer must give notice to Jackpot by email to{' '}
                    <a className="text-white" href="mailto:legal@jackpotbam.com">legal@jackpotbam.com</a>, with a
                    copy for operational matters to{' '}
                    <a className="text-white" href="mailto:support@jackpotbam.com">support@jackpotbam.com</a>.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s26" number="26" title="General Provisions">
                <LegalList
                    ordered
                    items={[
                        <><strong>Entire Agreement.</strong> These Terms, any Order Form, the Privacy Policy, the Data Processing Addendum, and any policies expressly incorporated are the entire agreement and supersede all prior or contemporaneous agreements on the subject.</>,
                        <><strong>Order of Precedence.</strong> In case of conflict: (1) a mutually executed Order Form (for the specific inconsistency), (2) the Data Processing Addendum (for personal-data processing), (3) these Terms, (4) Documentation.</>,
                        <><strong>Assignment.</strong> Customer may not assign these Terms without Jackpot's prior written consent. Jackpot may assign these Terms, in whole or in part, without restriction, including in connection with a merger, acquisition, reorganization, or sale of assets. Any purported assignment in violation of this Section is void.</>,
                        <><strong>No Third-Party Beneficiaries.</strong> There are no third-party beneficiaries to these Terms.</>,
                        <><strong>Independent Contractors.</strong> The parties are independent contractors. Nothing creates an agency, partnership, or joint venture.</>,
                        <><strong>Severability.</strong> If any provision is held unenforceable, it will be modified to the minimum extent necessary and the remainder of these Terms will remain in full force.</>,
                        <><strong>No Waiver.</strong> A failure to enforce any provision is not a waiver of future enforcement.</>,
                        <><strong>Construction.</strong> Headings are for convenience only. "Including" means "including without limitation." These Terms will not be construed against the drafter.</>,
                        <><strong>U.S. Government Rights.</strong> The Services are "commercial items" as defined at 48 C.F.R. §§ 2.101 and 12.212, and any use by U.S. Government end users is subject to these Terms.</>,
                    ]}
                />
            </LegalSection>

            <div className="mt-16 rounded-xl border border-white/[0.06] bg-white/[0.02] p-6 text-sm text-white/60">
                <p className="font-semibold text-white">Jackpot Brand Asset Management, LLC</p>
                <p className="mt-1">An Ohio limited liability company</p>
                <p className="mt-3">
                    100 Pheasant Woods Ct<br />
                    Loveland, OH 45140<br />
                    United States
                </p>
                <p className="mt-3">
                    General inquiries: <a className="text-white hover:underline" href="mailto:support@jackpotbam.com">support@jackpotbam.com</a>
                </p>
                <p>
                    Legal notices: <a className="text-white hover:underline" href="mailto:legal@jackpotbam.com">legal@jackpotbam.com</a>
                </p>
                <p className="mt-3 text-white/40 text-xs">
                    Jackpot™ and the Jackpot logo are trademarks of Jackpot Brand Asset Management, LLC.
                    Trademark registrations pending with the United States Patent and Trademark Office.
                </p>
            </div>
        </LegalPage>
    )
}
