import LegalPage, { LegalSection, LegalParagraph, LegalList } from '../../Components/Legal/LegalPage'

/**
 * Data Processing Addendum (DPA) for Jackpot Brand Asset Management, LLC.
 *
 * Drafting posture (decisions logged in docs/compliance/PATH_TO_GDPR.md):
 * - Processor-side contract covering Customer Content processed on behalf of Customer.
 * - Subprocessor change notice: 30 days; customer may terminate the affected Services
 *   without penalty if an acceptable alternative is not offered.
 * - Audit rights: satisfied primarily through third-party attestations (SOC 2 Type II /
 *   ISO 27001) when available; one direct audit per year at customer cost if attestations
 *   are insufficient.
 * - Breach notice to Customer: without undue delay and in any event within 48 hours after
 *   becoming aware (gives controllers ~24h runway to their own 72h GDPR deadline).
 * - End of service: 30 days to return / delete, extendable to 90 days; backup "beyond use"
 *   language mirrors the Privacy Policy.
 * - SCCs: EU Commission 2021 Module 2 (controller-to-processor) and Module 3
 *   (processor-to-processor); UK Addendum to the SCCs for UK personal data; Swiss
 *   amendments for Swiss personal data.
 * - Liability: subject to the limitation-of-liability cap in the Terms.
 *
 * This is counsel-ready draft copy, not a substitute for legal review.
 */
export default function DPA() {
    return (
        <LegalPage title="Data Processing Addendum" effectiveDate="April 18, 2026">
            <LegalParagraph>
                This Data Processing Addendum (<strong>"DPA"</strong>) forms part of, and is incorporated
                into, the Jackpot{' '}
                <a href="/terms" className="text-white hover:underline">Terms of Service</a> and any written
                order form, quote, or subscription agreement between Customer and{' '}
                <strong>Jackpot Brand Asset Management, LLC</strong> (<strong>"Jackpot"</strong>) that
                governs Customer's access to and use of the Services (collectively, the
                <strong> "Agreement"</strong>). This DPA reflects the parties' agreement on the processing
                of Personal Data under the Data Protection Laws.
            </LegalParagraph>

            <LegalParagraph>
                Capitalized terms used but not defined in this DPA have the meanings given to them in the
                Agreement. In the event of any conflict between this DPA and the Agreement with respect to
                the processing of Personal Data, this DPA controls. In the event of any conflict between
                this DPA and the Standard Contractual Clauses referenced in{' '}
                <strong>Section 8 (International Transfers)</strong>, the Standard Contractual Clauses
                control.
            </LegalParagraph>

            <LegalSection id="s1" number="1" title="Definitions">
                <LegalParagraph>
                    Unless otherwise defined below, each capitalized term has the meaning given to it in
                    the Data Protection Laws.
                </LegalParagraph>
                <LegalList
                    items={[
                        <><strong>"Customer Personal Data"</strong> means Personal Data contained within Customer Content that Jackpot processes on behalf of Customer under the Agreement.</>,
                        <><strong>"Data Protection Laws"</strong> means all laws applicable to the processing of Personal Data under the Agreement, including, as applicable, the EU General Data Protection Regulation 2016/679 (<strong>"GDPR"</strong>), the UK General Data Protection Regulation as incorporated into United Kingdom law (<strong>"UK GDPR"</strong>), the Swiss Federal Act on Data Protection (<strong>"FADP"</strong>), the California Consumer Privacy Act as amended by the California Privacy Rights Act (<strong>"CCPA/CPRA"</strong>), and other comparable U.S. state privacy laws.</>,
                        <><strong>"Controller," "Processor," "Data Subject," "Personal Data," "Personal Data Breach," "Processing,"</strong> and <strong>"Supervisory Authority"</strong> have the meanings given to them in the GDPR (or the equivalent meanings under applicable Data Protection Laws). Equivalent terms under the CCPA/CPRA, such as "Business," "Service Provider," and "Personal Information," apply where that law is applicable.</>,
                        <><strong>"Restricted Transfer"</strong> means a transfer of Personal Data from the European Economic Area, the United Kingdom, or Switzerland to a country or recipient that is not subject to an adequacy decision under the applicable Data Protection Law.</>,
                        <><strong>"Standard Contractual Clauses"</strong> or <strong>"SCCs"</strong> means (a) the standard contractual clauses annexed to European Commission Implementing Decision (EU) 2021/914 (the <strong>"EU SCCs"</strong>); (b) the International Data Transfer Addendum to the EU SCCs issued by the UK Information Commissioner's Office (the <strong>"UK Addendum"</strong>); and (c) equivalent Swiss-specific amendments required by the Swiss Federal Data Protection and Information Commissioner.</>,
                        <><strong>"Subprocessor"</strong> means any third party engaged by Jackpot to process Customer Personal Data on Jackpot's behalf in connection with the Services.</>,
                    ]}
                />
            </LegalSection>

            <LegalSection id="s2" number="2" title="Roles and Scope">
                <LegalParagraph>
                    <strong>(a) Roles.</strong> With respect to Customer Personal Data, Customer is the
                    Controller (or, where applicable, a Processor acting on behalf of a third-party
                    Controller), and Jackpot is the Processor. Where Customer acts as a Processor, Customer
                    represents that it has the authority of the underlying Controller to engage Jackpot as
                    a Subprocessor on terms no less protective than those set out in this DPA.
                </LegalParagraph>
                <LegalParagraph>
                    <strong>(b) Scope.</strong> This DPA applies to Jackpot's processing of Customer
                    Personal Data in the course of providing the Services. It does not apply to Personal
                    Data that Jackpot processes as a Controller for its own purposes, which is governed by
                    the Jackpot{' '}
                    <a href="/privacy" className="text-white hover:underline">Privacy Policy</a> (for
                    example, Personal Data about account administrators, billing contacts, website
                    visitors, and users of the Services for purposes of account management, billing,
                    security, product improvement, and communication).
                </LegalParagraph>
                <LegalParagraph>
                    <strong>(c) Processing Details.</strong> The subject matter, duration, nature, and
                    purpose of processing, the categories of Customer Personal Data, and the categories of
                    Data Subjects are described in <strong>Annex 1 (Processing Details)</strong>. Customer
                    acknowledges that it determines the categories of Data Subjects and Personal Data
                    uploaded to or generated in the Services; Jackpot has no obligation or ability to
                    independently verify those categories.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s3" number="3" title="Processing on Documented Instructions">
                <LegalParagraph>
                    Jackpot will process Customer Personal Data only (a) in accordance with Customer's
                    documented instructions, including the instructions set out in the Agreement, this DPA,
                    and any reasonable and lawful written instructions agreed between the parties; and
                    (b) as required to comply with applicable law. The Agreement (including this DPA and
                    Customer's use of the Services) constitutes Customer's complete and final documented
                    instructions to Jackpot.
                </LegalParagraph>
                <LegalParagraph>
                    If Jackpot believes that an instruction from Customer infringes applicable Data
                    Protection Laws, Jackpot will promptly inform Customer and may, without liability,
                    suspend performance of the relevant processing until the instruction is confirmed,
                    corrected, or withdrawn. If compliance with a legally binding request of a
                    governmental or regulatory authority would prevent Jackpot from acting on Customer's
                    instructions, Jackpot will notify Customer of that requirement before processing, to
                    the extent permitted by law.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s4" number="4" title="Confidentiality">
                <LegalParagraph>
                    Jackpot will ensure that personnel authorized to process Customer Personal Data are
                    bound by a duty of confidentiality (whether by written agreement, statute, or
                    professional obligation) and have received appropriate training on their obligations
                    under the Data Protection Laws. Access to Customer Personal Data is limited to
                    personnel who need access to perform the Services or to meet Jackpot's obligations to
                    Customer.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s5" number="5" title="Security">
                <LegalParagraph>
                    Jackpot will implement and maintain appropriate technical and organizational measures
                    designed to protect Customer Personal Data against accidental or unlawful destruction,
                    loss, alteration, unauthorized disclosure, or access, taking into account the state of
                    the art, the costs of implementation, the nature, scope, context, and purposes of
                    processing, and the risks to Data Subjects. A description of the measures in place as
                    of the effective date of this DPA is set out in{' '}
                    <strong>Annex 2 (Technical and Organizational Measures)</strong>. Jackpot may update
                    those measures from time to time provided that the overall level of protection is not
                    materially diminished.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s6" number="6" title="Subprocessors">
                <LegalParagraph>
                    <strong>(a) General authorization.</strong> Customer provides Jackpot with a general
                    written authorization to engage Subprocessors to process Customer Personal Data, as
                    permitted by Article 28(2) of the GDPR and equivalent provisions of other Data
                    Protection Laws. A current list of Subprocessors is published at{' '}
                    <a href="/subprocessors" className="text-white hover:underline">
                        jackpotbam.com/subprocessors
                    </a>{' '}
                    (the <strong>"Subprocessor Page"</strong>), which is incorporated into this DPA by
                    reference.
                </LegalParagraph>
                <LegalParagraph>
                    <strong>(b) Notice of changes.</strong> Jackpot will give Customer at least{' '}
                    <strong>thirty (30) days'</strong> prior notice of its intent to add or replace a
                    Subprocessor that processes Customer Personal Data. Notice will be provided by
                    updating the Subprocessor Page and by a notification to the administrative email
                    address on file for Customer's account (or by another reasonable means). Customer is
                    responsible for keeping that email address current and may subscribe to change alerts
                    where offered.
                </LegalParagraph>
                <LegalParagraph>
                    <strong>(c) Right to object.</strong> Customer may object, within the thirty (30) day
                    notice period, to the addition of a new Subprocessor on reasonable grounds relating to
                    the protection of Customer Personal Data, by written notice to{' '}
                    <a className="text-white hover:underline" href="mailto:legal@jackpotbam.com">legal@jackpotbam.com</a>.
                    The parties will discuss the objection in good faith. If Jackpot, in its reasonable
                    discretion, cannot make available a commercially reasonable alternative, Customer may
                    terminate, without penalty (other than payment for Services rendered before the
                    termination date and any non-cancellable third-party commitments), the portion of the
                    affected Services that cannot be provided without the objected-to Subprocessor. This
                    termination right is Customer's sole and exclusive remedy in the event of an
                    unresolved objection.
                </LegalParagraph>
                <LegalParagraph>
                    <strong>(d) Subprocessor obligations and liability.</strong> Before engaging a
                    Subprocessor to process Customer Personal Data, Jackpot will enter into a written
                    agreement with the Subprocessor imposing data-protection obligations that are, in
                    substance, no less protective than those set out in this DPA. Jackpot remains
                    responsible to Customer for the performance of each Subprocessor's obligations.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s7" number="7" title="Assistance to Customer">
                <LegalParagraph>
                    <strong>(a) Data-subject requests.</strong> Taking into account the nature of the
                    processing, Jackpot will provide reasonable assistance to Customer, by appropriate
                    technical and organizational measures and insofar as this is possible, for the
                    fulfillment of Customer's obligations to respond to requests by Data Subjects
                    exercising rights under the Data Protection Laws (for example, access, rectification,
                    erasure, restriction, portability, and objection). To the extent Customer's use of the
                    Services does not allow Customer to fulfill such a request itself, Jackpot will, on
                    Customer's written request, provide commercially reasonable assistance.
                </LegalParagraph>
                <LegalParagraph>
                    <strong>(b) Rights requests received by Jackpot.</strong> If Jackpot receives a request
                    from a Data Subject relating to Customer Personal Data, Jackpot will, unless legally
                    prohibited, promptly inform the Data Subject that the request should be directed to
                    Customer and, where appropriate, forward the request to Customer. Jackpot will not
                    respond substantively to the Data Subject except on Customer's instructions or as
                    required by law.
                </LegalParagraph>
                <LegalParagraph>
                    <strong>(c) DPIAs and consultation.</strong> Jackpot will provide reasonable assistance
                    to Customer with data-protection impact assessments and prior consultations with
                    Supervisory Authorities under Articles 35 and 36 of the GDPR (and equivalents under
                    other Data Protection Laws), taking into account the nature of the processing and the
                    information available to Jackpot.
                </LegalParagraph>
                <LegalParagraph>
                    <strong>(d) Fees.</strong> The assistance described in this Section 7 is included in
                    the Services at no additional charge, except where a request is repetitive, excessive,
                    or manifestly unfounded, or where fulfillment imposes a disproportionate burden, in
                    which case Jackpot may charge a reasonable fee or decline the request to the extent
                    permitted by law.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s8" number="8" title="International Transfers">
                <LegalParagraph>
                    <strong>(a) Hosting location.</strong> Jackpot processes Customer Personal Data
                    primarily in the United States. Customer acknowledges and agrees to such processing.
                </LegalParagraph>
                <LegalParagraph>
                    <strong>(b) EU SCCs.</strong> To the extent a transfer of Customer Personal Data from
                    the European Economic Area or a Member State of the European Union to Jackpot in the
                    United States is a Restricted Transfer under the GDPR, the parties are deemed to have
                    entered into the EU SCCs as follows:
                </LegalParagraph>
                <LegalList
                    items={[
                        <>Module Two (controller-to-processor) applies where Customer is the Controller of Customer Personal Data, and Module Three (processor-to-processor) applies where Customer is itself a Processor acting on behalf of a third-party Controller;</>,
                        <>in Clause 7, the docking clause does not apply;</>,
                        <>in Clause 9, Option 2 (general written authorization) applies with the thirty (30) day notice period set out in Section 6(b);</>,
                        <>in Clause 11, the optional independent-dispute-resolution language does not apply;</>,
                        <>in Clauses 17 and 18, the governing law and forum are the law and courts of the Republic of Ireland;</>,
                        <>Annexes I, II, and III to the EU SCCs are populated by reference to Annexes 1, 2, and 3 of this DPA, respectively.</>,
                    ]}
                />
                <LegalParagraph>
                    <strong>(c) UK Addendum.</strong> To the extent a Restricted Transfer of Customer
                    Personal Data under the UK GDPR is made to Jackpot in the United States, the parties
                    are deemed to have entered into the UK Addendum, which incorporates and amends the EU
                    SCCs set out above. In Table 4 of the UK Addendum, neither party may end the UK
                    Addendum as set out in Section 19 when the Approved Addendum changes.
                </LegalParagraph>
                <LegalParagraph>
                    <strong>(d) Swiss amendments.</strong> To the extent a Restricted Transfer of Customer
                    Personal Data under the FADP is made to Jackpot in the United States, the EU SCCs
                    apply as supplemented and amended to meet the requirements of the Swiss Federal Data
                    Protection and Information Commissioner.
                </LegalParagraph>
                <LegalParagraph>
                    <strong>(e) Alternative transfer mechanisms.</strong> If the EU Commission, the UK
                    government, or the Swiss authorities approves a new transfer mechanism that applies to
                    a Restricted Transfer contemplated by this DPA, Jackpot may, at its option and by
                    notice to Customer, rely on that mechanism in substitution for the Standard
                    Contractual Clauses.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s9" number="9" title="Personal Data Breach Notification">
                <LegalParagraph>
                    Jackpot will notify Customer without undue delay — and, where feasible, no later than{' '}
                    <strong>forty-eight (48) hours</strong> — after becoming aware of a Personal Data
                    Breach affecting Customer Personal Data. The initial notification will include the
                    information then reasonably available to Jackpot about the nature of the Personal Data
                    Breach and the likely consequences, and Jackpot will provide additional information as
                    it becomes available. Jackpot will take reasonable steps to contain and mitigate the
                    Personal Data Breach and will reasonably cooperate with Customer's investigation and
                    response.
                </LegalParagraph>
                <LegalParagraph>
                    Customer is responsible for notifying Supervisory Authorities and affected Data
                    Subjects to the extent required by the Data Protection Laws. Jackpot's notification
                    under this Section is not, and will not be construed as, an acknowledgment of fault or
                    liability by Jackpot with respect to the Personal Data Breach.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s10" number="10" title="Audits and Attestations">
                <LegalParagraph>
                    <strong>(a) Attestations.</strong> Jackpot will, on Customer's reasonable written
                    request and no more than once per year, make available to Customer a summary of the
                    most recent third-party attestation or audit reports that Jackpot obtains (for example,
                    a SOC 2 Type II report or ISO/IEC 27001 certification), where available. Such reports
                    are Jackpot's Confidential Information and may be redacted as appropriate to protect
                    the confidentiality and security of other customers and Jackpot's systems.
                </LegalParagraph>
                <LegalParagraph>
                    <strong>(b) Direct audit.</strong> If Customer reasonably believes that the
                    attestations described in Section 10(a) are insufficient to demonstrate compliance
                    with this DPA, Customer may, no more than once per twelve (12) month period, audit
                    Jackpot's processing of Customer Personal Data, subject to the following:
                </LegalParagraph>
                <LegalList
                    items={[
                        <>Customer must give Jackpot at least thirty (30) days' prior written notice;</>,
                        <>the audit must be conducted during normal business hours, at Customer's expense, in a manner that does not unreasonably interfere with Jackpot's operations or compromise the confidentiality or security of other customers;</>,
                        <>the auditor must be an independent third party reasonably acceptable to Jackpot and must be bound by written confidentiality obligations no less protective than those in the Agreement;</>,
                        <>the scope of the audit is limited to Jackpot's compliance with this DPA and does not extend to other customers, third parties, or matters unrelated to Customer Personal Data;</>,
                        <>the audit is conducted remotely and on a documentary basis where reasonably possible, and Jackpot may satisfy audit requirements by providing responses to reasonable questionnaires and documentation; and</>,
                        <>Customer will provide Jackpot with a copy of any audit report and will not disclose it to third parties except on a confidential basis to its legal, compliance, and data-protection advisors or as required by law.</>,
                    ]}
                />
                <LegalParagraph>
                    <strong>(c) Supervisory Authority.</strong> Where a Supervisory Authority with
                    jurisdiction requires an audit of Jackpot's processing of Customer Personal Data,
                    Jackpot will cooperate as required by the applicable Data Protection Law.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s11" number="11" title="Return or Deletion on Termination">
                <LegalParagraph>
                    On expiration or termination of the Agreement, Jackpot will, at Customer's written
                    election made within <strong>thirty (30) days</strong> of the effective date of
                    termination, either (a) delete Customer Personal Data from Jackpot's live production
                    systems, or (b) return Customer Personal Data to Customer in a commonly used machine-
                    readable format through the Services (for example, by leaving Customer's account in a
                    read-only state during the election period) and then delete it. If Customer does not
                    make an election within the thirty (30) day window, Jackpot will delete Customer
                    Personal Data from its live production systems in the ordinary course.
                </LegalParagraph>
                <LegalParagraph>
                    On Customer's written request, Jackpot may extend the election period up to a total of
                    ninety (90) days following termination to facilitate orderly data return. Reasonable
                    fees may apply to extended retention or to non-standard export formats.
                </LegalParagraph>
                <LegalParagraph>
                    Customer acknowledges that, following deletion from live production systems, Customer
                    Personal Data may remain in secure, encrypted rolling backups and disaster-recovery
                    copies for a limited period (generally not more than approximately thirty (30) days,
                    consistent with Jackpot's cloud-provider backup cycle) before it is overwritten in the
                    ordinary course. During that period, Jackpot will treat such data as{' '}
                    <strong>"beyond use"</strong>: it will not be used for any operational, analytical,
                    product-development, or AI-training purpose, and will be accessed only as reasonably
                    necessary for disaster recovery, audit, or where required by law or legal process.
                    Jackpot will not restore Customer Personal Data from backups solely to respond to
                    individual deletion requests.
                </LegalParagraph>
                <LegalParagraph>
                    Jackpot may retain Customer Personal Data to the extent, and for the period, required
                    by applicable law (including tax, accounting, audit, and recordkeeping obligations) or
                    reasonably necessary to establish, exercise, or defend legal claims. Any such retained
                    data will continue to be subject to the obligations of confidentiality and security in
                    this DPA.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s12" number="12" title="CCPA / CPRA Addendum">
                <LegalParagraph>
                    Where the CCPA/CPRA applies, Jackpot is a <strong>Service Provider</strong> (and, where
                    applicable, a <strong>Contractor</strong>) with respect to Customer Personal Data.
                    Jackpot will not (a) sell or share Customer Personal Data; (b) retain, use, or disclose
                    Customer Personal Data for any purpose other than the business purposes specified in
                    the Agreement, or as otherwise permitted by the CCPA/CPRA; (c) retain, use, or disclose
                    Customer Personal Data outside of the direct business relationship between Jackpot and
                    Customer; or (d) combine Customer Personal Data with personal information received from
                    other sources, except as permitted by the CCPA/CPRA. Jackpot certifies that it
                    understands these restrictions.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s13" number="13" title="Liability">
                <LegalParagraph>
                    Each party's liability arising out of or related to this DPA, whether in contract,
                    tort, or under any other theory of liability, is subject to the limitations and
                    exclusions of liability set out in the Agreement, and any reference in the Agreement
                    to the liability of a party means the aggregate liability of that party under the
                    Agreement, including this DPA, and not the liability of that party under this DPA
                    alone. Without limiting the generality of the foregoing, amounts payable by a party
                    under the Agreement will be treated as including liabilities arising under this DPA.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="s14" number="14" title="Term, Precedence, and General">
                <LegalParagraph>
                    <strong>(a) Term.</strong> This DPA is effective for the term of the Agreement and
                    will survive termination of the Agreement to the extent necessary to perform the
                    obligations in Section 11 (Return or Deletion on Termination).
                </LegalParagraph>
                <LegalParagraph>
                    <strong>(b) Precedence.</strong> In the event of a conflict between this DPA and any
                    other document forming part of the Agreement (other than the Standard Contractual
                    Clauses), this DPA controls with respect to the processing of Personal Data.
                </LegalParagraph>
                <LegalParagraph>
                    <strong>(c) Changes.</strong> Jackpot may update this DPA from time to time, provided
                    that the changes do not materially reduce the protections afforded to Customer
                    Personal Data. Material changes will be communicated in accordance with the notice
                    provisions of the Agreement.
                </LegalParagraph>
                <LegalParagraph>
                    <strong>(d) Governing law.</strong> Except where the Standard Contractual Clauses
                    require otherwise, this DPA is governed by the law set out in the Agreement.
                </LegalParagraph>
                <LegalParagraph>
                    <strong>(e) Severability.</strong> If any provision of this DPA is held to be invalid
                    or unenforceable, the remaining provisions will remain in full force and effect, and
                    the parties will cooperate in good faith to replace the invalid or unenforceable
                    provision with an enforceable provision that reflects, as closely as possible, the
                    intent of the original.
                </LegalParagraph>
            </LegalSection>

            <hr className="my-16 border-white/[0.06]" />

            <h2 className="text-2xl font-semibold text-white tracking-tight mt-16 mb-6">
                Annex 1 — Processing Details
            </h2>

            <LegalSection id="a1-1" title="A. Subject matter and duration">
                <LegalParagraph>
                    <strong>Subject matter:</strong> provision of the Services under the Agreement.
                </LegalParagraph>
                <LegalParagraph>
                    <strong>Duration:</strong> for the term of the Agreement and any post-termination
                    retention period described in Section 11.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="a1-2" title="B. Nature and purpose of processing">
                <LegalParagraph>
                    Hosting, storage, organization, transformation (including AI-assisted tagging,
                    metadata extraction, content generation, embedding, search, and editing), access
                    management, transmission, backup, security, and other operations reasonably necessary
                    to provide the Services and to meet Jackpot's legal obligations.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="a1-3" title="C. Types of Personal Data">
                <LegalList
                    items={[
                        <>Identifiers of Customer's authorized users (for example, name, email, and similar account identifiers) that Customer has chosen to make available to Jackpot;</>,
                        <>Any Personal Data contained in Customer Content that Customer uploads, links to, or generates through the Services (for example, images, videos, documents, creative files, and associated metadata);</>,
                        <>Usage and activity data attributable to identified or identifiable individuals (for example, log-in timestamps, actions taken, and device metadata) collected by the Services in the course of providing and securing the Services;</>,
                        <>Any other Personal Data that Customer elects to process through the Services.</>,
                    ]}
                />
                <LegalParagraph>
                    The Services are not designed for, and Customer must not use them to process, special
                    categories of Personal Data under Article 9 of the GDPR, criminal-conviction data under
                    Article 10 of the GDPR, personal information of children, or government-issued
                    identifiers, in each case except to the extent that the parties have agreed in writing
                    to appropriate additional safeguards.
                </LegalParagraph>
            </LegalSection>

            <LegalSection id="a1-4" title="D. Categories of Data Subjects">
                <LegalList
                    items={[
                        <>Customer's authorized users (for example, employees, contractors, agency partners, and collaborators);</>,
                        <>Individuals whose Personal Data is contained in Customer Content (for example, individuals appearing in images or videos, individuals referenced in metadata, and subjects of creative briefs);</>,
                        <>Any other category of Data Subject whose Personal Data Customer elects to process through the Services.</>,
                    ]}
                />
            </LegalSection>

            <LegalSection id="a1-5" title="E. Frequency and obligations">
                <LegalParagraph>
                    Continuous, for the duration of the Agreement. Jackpot's obligations as Processor are
                    set out in this DPA.
                </LegalParagraph>
            </LegalSection>

            <hr className="my-16 border-white/[0.06]" />

            <h2 className="text-2xl font-semibold text-white tracking-tight mt-16 mb-6">
                Annex 2 — Technical and Organizational Measures
            </h2>

            <LegalParagraph>
                Jackpot has implemented and maintains the following technical and organizational measures,
                which may be updated from time to time provided that the overall level of protection is
                not materially diminished.
            </LegalParagraph>

            <LegalSection id="a2-1" title="A. Information-security program">
                <LegalList
                    items={[
                        <>Documented information-security policies, reviewed periodically;</>,
                        <>Assignment of internal responsibility for information security;</>,
                        <>Security-awareness and data-protection training for personnel with access to Customer Personal Data;</>,
                        <>Confidentiality obligations binding on personnel and contractors.</>,
                    ]}
                />
            </LegalSection>

            <LegalSection id="a2-2" title="B. Access control">
                <LegalList
                    items={[
                        <>Individual user accounts with password authentication; passwords stored using industry-standard one-way hashing;</>,
                        <>Role-based access control within the Services, with separate administrator, member, and guest permissions;</>,
                        <>Principle of least privilege for internal access to production systems;</>,
                        <>Session management with server-side session storage, configurable session lifetime, and secure session cookies.</>,
                    ]}
                />
            </LegalSection>

            <LegalSection id="a2-3" title="C. Encryption">
                <LegalList
                    items={[
                        <>Encryption of Customer Personal Data in transit using Transport Layer Security (TLS) with current industry-standard cipher suites;</>,
                        <>Encryption of Customer Personal Data at rest using Jackpot's cloud-provider default encryption (for example, AWS-managed keys for S3 object storage and managed relational databases);</>,
                        <>Encrypted storage of secrets and credentials used by the Services.</>,
                    ]}
                />
            </LegalSection>

            <LegalSection id="a2-4" title="D. Application security">
                <LegalList
                    items={[
                        <>Use of a modern web-application framework with built-in protections against common application-layer threats (for example, cross-site request forgery, mass-assignment, and SQL injection);</>,
                        <>Dependency scanning and timely application of security patches;</>,
                        <>Code review for changes to production systems;</>,
                        <>Structured logging and error reporting with personal-data minimization.</>,
                    ]}
                />
            </LegalSection>

            <LegalSection id="a2-5" title="E. Network and infrastructure security">
                <LegalList
                    items={[
                        <>Hosting with reputable cloud-infrastructure providers (primarily Amazon Web Services) that maintain recognized security certifications (for example, ISO/IEC 27001 and SOC 2);</>,
                        <>Firewalls, security groups, and network segmentation to limit exposure of production resources;</>,
                        <>Use of managed services for databases, object storage, and similar components where appropriate.</>,
                    ]}
                />
            </LegalSection>

            <LegalSection id="a2-6" title="F. Monitoring, logging, and incident response">
                <LegalList
                    items={[
                        <>Application and infrastructure logging of security-relevant events;</>,
                        <>Centralized error and exception monitoring;</>,
                        <>Documented incident-response procedure covering detection, containment, investigation, notification, and post-incident review;</>,
                        <>Dedicated channels for external vulnerability reports (including at{' '}
                        <a className="text-white hover:underline" href="mailto:security@jackpotbam.com">security@jackpotbam.com</a>).</>,
                    ]}
                />
            </LegalSection>

            <LegalSection id="a2-7" title="G. Business continuity and disaster recovery">
                <LegalList
                    items={[
                        <>Automated backups of production data on a rolling cycle (generally not more than approximately thirty (30) days);</>,
                        <>Backups stored in encrypted form within the cloud-provider environment;</>,
                        <>Periodic review of backup and recovery posture.</>,
                    ]}
                />
            </LegalSection>

            <LegalSection id="a2-8" title="H. Subprocessor management">
                <LegalList
                    items={[
                        <>Due diligence on Subprocessors' security and data-protection practices before engagement;</>,
                        <>Written agreements with Subprocessors that impose data-protection obligations no less protective than those in this DPA;</>,
                        <>Public list of Subprocessors maintained at{' '}
                        <a href="/subprocessors" className="text-white hover:underline">
                            jackpotbam.com/subprocessors
                        </a>.</>,
                    ]}
                />
            </LegalSection>

            <LegalSection id="a2-9" title="I. Data minimization and segregation">
                <LegalList
                    items={[
                        <>Logical segregation of Customer data through tenant identifiers;</>,
                        <>Default configuration designed to minimize collection of Personal Data beyond what is reasonably necessary to provide the Services;</>,
                        <>Controls to prevent the use of Customer Personal Data for unrelated purposes, including training of Jackpot's general-purpose machine-learning models.</>,
                    ]}
                />
            </LegalSection>

            <hr className="my-16 border-white/[0.06]" />

            <h2 className="text-2xl font-semibold text-white tracking-tight mt-16 mb-6">
                Annex 3 — Subprocessors
            </h2>

            <LegalParagraph>
                The current list of Subprocessors engaged by Jackpot to process Customer Personal Data is
                published at{' '}
                <a href="/subprocessors" className="text-white hover:underline">
                    jackpotbam.com/subprocessors
                </a>{' '}
                and is incorporated into this DPA by reference. Categories of Subprocessors include:
            </LegalParagraph>
            <LegalList
                items={[
                    <>Cloud-infrastructure and storage providers;</>,
                    <>Transactional email and notification providers;</>,
                    <>Payment and billing providers;</>,
                    <>Error-monitoring and analytics providers;</>,
                    <>AI model and inference providers;</>,
                    <>Customer-support and communications providers.</>,
                ]}
            />
            <LegalParagraph>
                Notice of additions or replacements will be given in accordance with Section 6(b).
            </LegalParagraph>

            <div className="mt-16 rounded-xl border border-white/[0.06] bg-white/[0.02] p-6 text-sm text-white/60">
                <p className="font-semibold text-white">Jackpot Brand Asset Management, LLC</p>
                <p className="mt-1">An Ohio limited liability company</p>
                <p className="mt-3">
                    Ohio
                </p>
                <p className="mt-3">
                    Privacy inquiries: <a className="text-white hover:underline" href="mailto:privacy@jackpotbam.com">privacy@jackpotbam.com</a>
                </p>
                <p>
                    Legal notices: <a className="text-white hover:underline" href="mailto:legal@jackpotbam.com">legal@jackpotbam.com</a>
                </p>
                <p>
                    Security / incident reports: <a className="text-white hover:underline" href="mailto:security@jackpotbam.com">security@jackpotbam.com</a>
                </p>
            </div>
        </LegalPage>
    )
}
