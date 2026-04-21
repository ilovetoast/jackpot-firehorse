<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inbound public contact / newsletter / sales-inquiry lead — see
 * `database/migrations/*_create_contact_leads_table.php` for schema rationale.
 *
 * Intentionally not tenant-scoped: these rows come from unauthenticated
 * visitors before any tenant exists. Once a lead converts, `converted_to_tenant_id`
 * links the row to the customer tenant for attribution.
 *
 * @property int $id
 * @property string $kind             'contact_form' | 'newsletter' | 'sales_inquiry'
 * @property string $email
 * @property string|null $name
 * @property string|null $phone
 * @property string|null $job_title
 * @property string|null $company
 * @property string|null $company_website
 * @property string|null $plan_interest
 * @property string|null $company_size
 * @property string|null $industry
 * @property string|null $use_case
 * @property string|null $brand_count
 * @property string|null $timeline
 * @property string|null $heard_from
 * @property string|null $message
 * @property array<string,mixed>|null $details
 * @property string|null $source
 * @property array<string,mixed>|null $utm
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property bool $consent_marketing
 * @property string $status
 * @property int|null $rating
 * @property int|null $assigned_to_user_id
 * @property int|null $converted_to_tenant_id
 * @property \Carbon\Carbon|null $last_contacted_at
 * @property \Carbon\Carbon|null $converted_at
 * @property \Carbon\Carbon|null $unsubscribed_at
 * @property \Carbon\Carbon|null $processing_objected_at  Art. 21 objection (non-account lead rows)
 */
class ContactLead extends Model
{
    public const KIND_CONTACT_FORM = 'contact_form';
    public const KIND_NEWSLETTER = 'newsletter';
    public const KIND_SALES_INQUIRY = 'sales_inquiry';

    public const KINDS = [
        self::KIND_CONTACT_FORM,
        self::KIND_NEWSLETTER,
        self::KIND_SALES_INQUIRY,
    ];

    public const STATUS_NEW = 'new';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_QUALIFIED = 'qualified';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_DISQUALIFIED = 'disqualified';
    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    /**
     * Sales qualification enum choices. Kept as string enums so they're stable
     * across DB backends and match the select options rendered on the frontend.
     * When editing these, also update {@see resources/js/Components/Marketing/SalesInquiryForm.jsx}.
     */
    public const COMPANY_SIZES = ['1-10', '11-50', '51-200', '201-1000', '1001-5000', '5000+'];

    public const INDUSTRIES = [
        'agency',
        'saas',
        'retail_cpg',
        'finance',
        'healthcare',
        'media_entertainment',
        'education',
        'nonprofit',
        'manufacturing',
        'other',
    ];

    public const USE_CASES = [
        'brand_management',
        'asset_management',
        'agency_client_work',
        'creator_program',
        'compliance_governance',
        'other',
    ];

    public const BRAND_COUNTS = ['1', '2-5', '6-20', '21-50', '50+'];

    public const TIMELINES = ['exploring', '0-3mo', '3-6mo', '6-12mo', '12+mo'];

    public const PLAN_INTERESTS = ['enterprise', 'agency', 'default'];

    protected $fillable = [
        'kind',
        'email',
        'name',
        'phone',
        'job_title',
        'company',
        'company_website',
        'plan_interest',
        'company_size',
        'industry',
        'use_case',
        'brand_count',
        'timeline',
        'heard_from',
        'message',
        'details',
        'source',
        'utm',
        'ip_address',
        'user_agent',
        'consent_marketing',
        'status',
        'rating',
        'assigned_to_user_id',
        'converted_to_tenant_id',
        'last_contacted_at',
        'converted_at',
        'unsubscribed_at',
        'processing_objected_at',
    ];

    protected function casts(): array
    {
        return [
            'utm' => 'array',
            'details' => 'array',
            'consent_marketing' => 'boolean',
            'rating' => 'integer',
            'last_contacted_at' => 'datetime',
            'converted_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'processing_objected_at' => 'datetime',
        ];
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function convertedToTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'converted_to_tenant_id');
    }

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_NEW, self::STATUS_CONTACTED, self::STATUS_QUALIFIED]);
    }

    /**
     * Human-readable labels for the qualification enums. Used by the email
     * notification so the sales inbox reads "Company size: 51–200" instead of
     * "company_size: 51-200". Frontend has its own label map for select UI.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            // company sizes
            '1-10' => '1–10',
            '11-50' => '11–50',
            '51-200' => '51–200',
            '201-1000' => '201–1,000',
            '1001-5000' => '1,001–5,000',
            '5000+' => '5,000+',
            // industries
            'agency' => 'Agency / Creative services',
            'saas' => 'Software / SaaS',
            'retail_cpg' => 'Retail / CPG',
            'finance' => 'Financial services',
            'healthcare' => 'Healthcare',
            'media_entertainment' => 'Media & Entertainment',
            'education' => 'Education',
            'nonprofit' => 'Nonprofit',
            'manufacturing' => 'Manufacturing',
            'other' => 'Other',
            // use cases
            'brand_management' => 'Brand management',
            'asset_management' => 'Creative / asset management',
            'agency_client_work' => 'Agency client work',
            'creator_program' => 'Creator / partner program',
            'compliance_governance' => 'Brand compliance & governance',
            // timelines
            'exploring' => 'Just exploring',
            '0-3mo' => 'Within 3 months',
            '3-6mo' => '3–6 months',
            '6-12mo' => '6–12 months',
            '12+mo' => '12+ months',
            // brand counts
            '1' => '1 brand',
            '2-5' => '2–5 brands',
            '6-20' => '6–20 brands',
            '21-50' => '21–50 brands',
            '50+' => '50+ brands',
        ];
    }

    public static function label(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::labels()[$value] ?? $value;
    }
}
