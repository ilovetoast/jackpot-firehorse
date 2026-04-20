<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public-facing inbound contact capture + lightweight sales CRM.
 *
 * Deliberately a single table rather than reusing `tickets` (which is tenant-
 * and user-scoped, fires SLA/assignment side effects on create, and is meant
 * for post-sale customer support). Public marketing inbound comes from
 * unauthenticated visitors who have neither a tenant_id nor a user_id, so it
 * lives here. The CRM columns (status, rating, assigned_to_user_id,
 * converted_to_tenant_id, *_at) are all nullable — the table is usable from
 * day one as a pure capture log, and a sales UI can be layered on later
 * without a schema change.
 *
 * See docs discussion around Option B in the contact-form thread.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_leads', function (Blueprint $table) {
            $table->id();

            // Which inbound flow produced this row. Single table handles the
            // marketing quick-contact form, newsletter signup, and the longer
            // sales / demo-request form so sales has one place to look.
            $table->string('kind'); // 'contact_form' | 'newsletter' | 'sales_inquiry'

            $table->string('email');
            $table->string('name')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('job_title')->nullable();
            $table->string('company')->nullable();
            $table->string('company_website')->nullable();

            // Matches the ?plan= query param on /contact (enterprise, agency,
            // default). Useful for routing leads to the right sales motion.
            $table->string('plan_interest')->nullable();

            // ── Sales qualification (nullable; only the sales_inquiry kind
            // populates these, contact_form/newsletter leave them null). Stored
            // as short enum-style strings for cheap filtering in the future CRM
            // UI — validation lives in the controller + ContactLead::* constants.
            $table->string('company_size')->nullable();   // '1-10', '11-50', '51-200', '201-1000', '1001-5000', '5000+'
            $table->string('industry')->nullable();       // see ContactLead::INDUSTRIES
            $table->string('use_case')->nullable();       // primary reason they're looking — see USE_CASES
            $table->string('brand_count')->nullable();    // '1', '2-5', '6-20', '21-50', '50+' (Jackpot is brand-scoped)
            $table->string('timeline')->nullable();       // 'exploring', '0-3mo', '3-6mo', '6-12mo', '12+mo'
            $table->string('heard_from')->nullable();     // free-text "how did you hear about us"

            $table->text('message')->nullable();

            // Overflow bucket for future fields we don't want to migrate for.
            // Avoid promoting anything filter-worthy to JSON — add a real column.
            $table->json('details')->nullable();

            // Where on the marketing site this was submitted from, plus any
            // UTM params we can capture for attribution. JSON keeps this
            // open-ended without schema churn when marketing adds new params.
            $table->string('source')->nullable();
            $table->json('utm')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            // CAN-SPAM / GDPR: newsletter signups require explicit opt-in.
            // Contact form submissions set this true only if the visitor also
            // checked the "send me product updates" box.
            $table->boolean('consent_marketing')->default(false);

            // ── CRM fields (all nullable; filled in by sales later) ───────
            // Leaving these null on insert means the table works as a pure
            // capture log on day one — a sales UI can use them once built.
            $table->string('status')->default('new'); // new|contacted|qualified|converted|disqualified|unsubscribed
            $table->unsignedTinyInteger('rating')->nullable(); // 1–5 sales-assigned quality
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('converted_to_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();

            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();

            $table->timestamps();

            // A single email may legitimately appear once as a contact_form
            // lead and once as a newsletter subscriber — don't collapse them.
            $table->unique(['email', 'kind']);

            $table->index('status');
            $table->index('kind');
            $table->index('created_at');
            $table->index('assigned_to_user_id');
            // Common CRM filters: company size, industry, timeline (e.g.
            // "enterprise-size leads in financial services closing in 3–6mo").
            $table->index(['kind', 'company_size']);
            $table->index(['kind', 'industry']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_leads');
    }
};
