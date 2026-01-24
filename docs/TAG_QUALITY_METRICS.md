# Tag Quality & Trust Metrics - Phase J.2.6

**Status:** ✅ IMPLEMENTED  
**Last Updated:** January 2026  
**Dependencies:** Phase J.2.2 (AI Tagging Controls), Phase J.2.3 (Tag UX), Phase J.2.5 (Company AI Settings UI)

---

## Overview

The Tag Quality & Trust Metrics system provides company admins with read-only analytics to understand AI tagging performance. This helps identify which AI-generated tags are valuable and which patterns indicate areas for improvement.

**Critical Principle:** This is **read-only analytics + observability only**. No AI behavior changes, no policy modifications, no automatic tuning.

---

## Business Value

### What Admins Can Learn

1. **Tag Usefulness** - Which AI tags are consistently accepted vs dismissed
2. **Confidence Correlation** - Whether high AI confidence predicts user acceptance
3. **Trust Patterns** - Identify tags that are generated frequently but never used
4. **Quality Trends** - Track improvement or degradation over time
5. **Auto-Apply Performance** - How well auto-applied tags are retained by users

### What This Does NOT Do

❌ **Auto-tune AI models** - No feedback loops to AI systems  
❌ **Modify tagging behavior** - No policy changes based on metrics  
❌ **Generate alerts** - No automated warnings or notifications  
❌ **Change UI behavior** - No hiding of "bad" tags from users  
❌ **Trigger new AI calls** - Pure analytics from existing data

---

## Metrics Computed

### 1️⃣ Core Acceptance Metrics

**Tag Acceptance Rate**
```
acceptance_rate = accepted_candidates / total_candidates
```
- **Source Data:** `asset_tag_candidates` where `resolved_at IS NOT NULL`
- **Meaning:** Percentage of AI suggestions that users accepted
- **Good Range:** 40%+ indicates useful AI suggestions

**Tag Dismissal Rate**
```
dismissal_rate = dismissed_candidates / total_candidates  
```
- **Source Data:** `asset_tag_candidates` where `dismissed_at IS NOT NULL`
- **Meaning:** Percentage of AI suggestions that users explicitly rejected
- **Watch For:** >60% dismissal may indicate noisy AI output

### 2️⃣ Applied Tags Analysis

**Manual vs AI Tag Ratio**
```
manual_ai_ratio = manual_tags / ai_tags
```
- **Source Data:** `asset_tags` grouped by `source` field
- **Meaning:** How much users rely on manual vs AI tagging
- **Interpretation:** 
  - Ratio > 2:1 → Users prefer manual tagging
  - Ratio < 1:2 → Users trust AI tagging

**Auto-Applied Tag Count**
- **Source Data:** `asset_tags` where `source = 'ai:auto'`
- **Meaning:** Total tags applied automatically by AI
- **Note:** Retention tracking requires removal event logging (future enhancement)

### 3️⃣ Confidence Analysis  

**Average Confidence by Outcome**
- **Accepted Tags:** Average confidence of tags users accepted
- **Dismissed Tags:** Average confidence of tags users rejected
- **Correlation:** Whether higher confidence predicts acceptance

**Confidence Band Breakdown**
| Band | Range | Purpose |
|------|--------|----------|
| **Highest** | 98-100% | Premium AI suggestions |
| **High** | 95-98% | Strong AI suggestions |
| **Good** | 90-95% | Standard AI suggestions |
| **Medium** | 80-90% | Lower confidence suggestions |
| **Low** | Below 80% | Questionable suggestions |

---

## Trust Signals (Problematic Patterns)

### 🔍 Pattern Detection

**High Generation, Low Acceptance**
- **Criteria:** >10 candidates generated, <30% acceptance rate
- **Meaning:** AI frequently suggests this tag, but users don't find it valuable
- **Example:** "professional" generated 50 times, accepted 3 times (6%)

**High Dismissal Frequency**
- **Criteria:** >50% of candidates for this tag are dismissed
- **Meaning:** Users actively reject this tag suggestion
- **Example:** "corporate" dismissed in 80% of suggestions

**Zero Acceptance Tags**
- **Criteria:** >5 candidates generated, 0% ever accepted
- **Meaning:** AI suggests this tag, but it's never useful to users
- **Example:** "image" generated 15 times, never accepted

**Confidence Trust Drops**
- **Criteria:** High AI confidence (≥90%) but low user acceptance (<40%)
- **Meaning:** AI is overconfident about tags users don't want
- **Example:** "marketing" at 94% confidence but 25% acceptance

### ⚠️ Important: Signals Are Informational Only

These patterns **do not trigger automatic changes**. They provide:
- **Visibility** into AI performance trends
- **Data for future AI model improvements** 
- **Evidence for manual tag rule adjustments**
- **Insights for user training needs**

---

## Data Sources & Calculations

### Source Tables (Read-Only)

**`asset_tag_candidates`** (Phase J.1)
- `resolved_at` → Tag was accepted (moved to asset_tags)
- `dismissed_at` → Tag was explicitly dismissed by user
- `confidence` → AI confidence score (0-1)
- `producer = 'ai'` → AI-generated candidates only

**`asset_tags`** (Phase J.1) 
- `source = 'manual'` → User manually added
- `source = 'ai'` → AI suggested, user accepted
- `source = 'ai:auto'` → Auto-applied by AI (Phase J.2.2)
- `confidence` → Preserved from original candidate

**`assets`** (Phase C)
- `tenant_id` → Ensures proper tenant scoping

### Query Examples

**Acceptance Rate Calculation:**
```sql
SELECT 
    COUNT(*) as total,
    COUNT(CASE WHEN resolved_at IS NOT NULL THEN 1 END) as accepted,
    COUNT(CASE WHEN dismissed_at IS NOT NULL THEN 1 END) as dismissed
FROM asset_tag_candidates atc
JOIN assets a ON atc.asset_id = a.id  
WHERE a.tenant_id = ? 
  AND atc.producer = 'ai'
  AND atc.created_at >= ?
  AND atc.created_at <= ?
```

**Per-Tag Quality Analysis:**
```sql
SELECT 
    atc.tag,
    COUNT(*) as total_generated,
    COUNT(CASE WHEN atc.resolved_at IS NOT NULL THEN 1 END) as accepted,
    AVG(atc.confidence) as avg_confidence,
    AVG(CASE WHEN atc.resolved_at IS NOT NULL THEN atc.confidence END) as avg_confidence_accepted
FROM asset_tag_candidates atc
JOIN assets a ON atc.asset_id = a.id
WHERE a.tenant_id = ? AND atc.producer = 'ai'
GROUP BY atc.tag
ORDER BY total_generated DESC
```

---

## User Interface

### Navigation & Access

**Location:** Company Settings > Tag Quality (new tab)
**Permission Required:** Company owner or admin role (tenant-based)
**Position:** Between "AI Settings" and "AI Usage" tabs

**Tab Order:**
1. Company Information
2. Plan & Billing
3. Team Members  
4. Brands Settings
5. Metadata
6. AI Settings
7. **Tag Quality** ← (NEW)
8. AI Usage
9. Ownership Transfer

### UI Sections

**1. Time Range Selector**
- Month picker (YYYY-MM format)
- Export CSV button
- Default: Current month

**2. Summary Cards**
- **Acceptance Rate** (green) - % of candidates accepted
- **Dismissal Rate** (red) - % of candidates dismissed  
- **Manual:AI Ratio** (indigo) - Balance of manual vs AI tags
- **Auto-Applied Count** (purple) - Total auto-applied tags

**3. Confidence Analysis**
- Average confidence for accepted vs dismissed tags
- Correlation indicator (positive/negative)
- Visual comparison of confidence levels

**4. Top Tags Lists**
- **Most Accepted Tags** - Best performing AI suggestions
- **Most Dismissed Tags** - Problematic AI suggestions  
- Trust signal indicators for concerning patterns

**5. Trust Signals Panel**
- **High Generation, Low Acceptance** - Frequently suggested but rarely used
- **Never Accepted** - Tags that are never useful despite multiple suggestions
- **High Confidence, Low Trust** - AI overconfidence on unpopular tags
- Clear explanation that these are informational only

**6. Confidence Bands Chart**
- Horizontal bar chart showing acceptance rates by confidence level
- Visual validation of confidence → acceptance correlation

### State Handling

**✅ AI Enabled + Data Present**
- Full dashboard with all metrics and visualizations
- Export functionality available
- Interactive time range selection

**🚫 AI Disabled**  
- Gray info box: "AI tagging is disabled"
- Link to AI Settings to enable
- No metrics displayed

**🟡 Zero Data**
- Blue info box: "No AI tagging data for [time period]"
- Explanation that metrics appear after AI generates suggestions
- Time range selector still available

**🔴 API Error**
- Red error panel with retry functionality  
- Error message display
- Developer console hint for troubleshooting

**🔒 Permission Denied**
- Yellow warning about admin access required
- Clear explanation of permission requirements
- No retry option (expected state)

---

## API Endpoints

### GET `/api/companies/ai-tag-metrics`

**Permission:** Company owner or admin (tenant-based)  
**Parameters:**
- `time_range` (optional) - Format: 'YYYY-MM', default: current month

**Response Structure:**
```json
{
    "summary": {
        "time_range": "2026-01",
        "ai_enabled": true,
        "total_candidates": 450,
        "accepted_candidates": 180,
        "dismissed_candidates": 95,
        "acceptance_rate": 0.400,
        "dismissal_rate": 0.211,
        "total_tags": 220,
        "manual_tags": 40,
        "ai_tags": 180,
        "auto_applied_tags": 25,
        "manual_ai_ratio": 0.22,
        "avg_confidence_accepted": 0.934,
        "avg_confidence_dismissed": 0.812,
        "confidence_correlation": true
    },
    "tags": {
        "tags": [
            {
                "tag": "photography",
                "total_generated": 45,
                "accepted": 38,
                "dismissed": 5,
                "acceptance_rate": 0.844,
                "dismissal_rate": 0.111,
                "avg_confidence": 0.923,
                "trust_signals": []
            }
        ]
    },
    "confidence": {
        "confidence_bands": [
            {
                "confidence_band": "98-100%",
                "total_candidates": 125,
                "accepted": 98,
                "acceptance_rate": 0.784
            }
        ]
    },
    "trust_signals": {
        "signals": {
            "high_generation_low_acceptance": [],
            "zero_acceptance_tags": [],
            "confidence_trust_drops": []
        },
        "summary": {
            "total_problematic_tags": 0
        }
    }
}
```

### GET `/api/companies/ai-tag-metrics/export`

**Permission:** Company owner or admin (tenant-based)
**Parameters:**
- `time_range` (optional) - Format: 'YYYY-MM'

**Response:** CSV file download with headers:
- Tag, Total Generated, Accepted, Dismissed
- Acceptance Rate, Dismissal Rate  
- Avg Confidence, Avg Confidence (Accepted), Avg Confidence (Dismissed)
- Trust Signals

---

## Performance & Caching

### Caching Strategy

**Cache Keys:**
- `tag_quality_summary:{tenant_id}:{time_range}` (15 min TTL)
- `tag_quality_tags:{tenant_id}:{time_range}:{limit}` (15 min TTL)
- `tag_quality_confidence:{tenant_id}:{time_range}` (15 min TTL)

**Cache Invalidation:**
- Manual: Admin can force refresh via UI
- Automatic: 15-minute TTL ensures reasonably fresh data
- Selective: Could be invalidated when new tags are accepted/dismissed

### Query Optimization

**Database Indexes Used:**
- `asset_tag_candidates(asset_id, created_at, producer)`
- `asset_tags(asset_id, source, created_at)`
- `assets(tenant_id)`

**Query Patterns:**
- All queries scoped to tenant for security
- Date range filtering for time-based metrics
- Aggregations use database-level GROUP BY for performance
- LIMIT clauses prevent excessive data loading

### UI Performance

**Frontend Optimizations:**
- Debounced time range changes (300ms)
- Loading states for all async operations
- Error boundaries to prevent crashes
- Memoized calculations where appropriate

---

## Security & Permissions

### Access Control

**Required Role:** Company owner or admin (tenant-based)
**Permission Check:**
```php
$currentOwner = $tenant->owner();
$isCurrentUserOwner = $currentOwner && $currentOwner->id === $user->id;
$tenantRole = $user->getRoleForTenant($tenant);

if (!$isCurrentUserOwner && !in_array($tenantRole, ['owner', 'admin'])) {
    return 403; // Permission denied
}
```

### Data Protection

**Tenant Isolation:**
- All queries include `WHERE tenant_id = ?` conditions
- No cross-tenant data leakage possible
- Cache keys include tenant ID

**Data Sensitivity:**
- Read-only access to existing data
- No PII exposure (only tag names and metrics)
- No sensitive business logic revealed

---

## What Metrics Mean (Plain Language)

### For Company Admins

**"How good is our AI tagging?"**
- **High acceptance rate (60%+)** = AI suggests useful tags most of the time
- **Low acceptance rate (<30%)** = AI suggestions often miss the mark
- **Confidence correlation** = AI knows when it's right vs wrong

**"Are we getting value from AI?"**
- **Manual:AI ratio** shows reliance on AI vs manual effort
- **Auto-applied retention** shows if users keep automated tags
- **Trust signals** highlight problematic patterns to address

**"Should we adjust our settings?"**
- **Zero acceptance tags** = Consider adding to block list
- **High dismissal frequency** = Consider lowering auto-apply confidence threshold
- **Confidence trust drops** = AI confidence thresholds may need adjustment

### For Data Interpretation

**Good Patterns:**
- Acceptance rate: 50-70%
- Confidence correlation: Positive (higher confidence = higher acceptance)
- Few trust signals (problematic patterns)
- Balanced manual:AI ratio based on company needs

**Concerning Patterns:**
- Acceptance rate: <30% (AI not providing value)
- High confidence, low trust (AI overconfident)
- Many "never accepted" tags (wasted AI effort)
- Auto-applied tags frequently removed (users don't trust automation)

**Neutral Patterns:**
- High manual:AI ratio (users prefer control - not necessarily bad)
- Seasonal variations in tag types/acceptance
- New tag types appearing (business evolution)

---

## Technical Implementation

### Service Architecture

**TagQualityMetricsService**
- **Pure calculation service** - no side effects
- **Cached queries** for performance
- **Tenant-scoped** for security
- **Time-range parameterized** for flexibility

**Key Methods:**
- `getSummaryMetrics()` - Overall acceptance/dismissal rates
- `getTagMetrics()` - Per-tag performance analysis  
- `getConfidenceMetrics()` - Confidence band breakdown
- `getTrustSignals()` - Problematic pattern detection

### API Design

**RESTful endpoints:**
- `GET /api/companies/ai-tag-metrics` - JSON metrics data
- `GET /api/companies/ai-tag-metrics/export` - CSV download

**Error Handling:**
- 403 Forbidden - Permission denied (expected for non-admins)
- 400 Bad Request - Invalid tenant context
- 500 Internal Server Error - Logged with full context

**Response Format:**
- Consistent JSON structure
- Human-readable field names
- Percentage values as decimals (0-1)
- Clear data groupings (summary, tags, confidence, trust_signals)

### Frontend Architecture

**React Component:** `TagQuality.jsx`
- **Graceful error handling** - No crashes on any error condition
- **Multiple loading states** - Loading, error, permission denied, no data, success
- **Interactive features** - Time range selection, CSV export
- **Accessible design** - ARIA labels, keyboard navigation

**Integration:** Company Settings page
- New "Tag Quality" tab between AI Settings and AI Usage
- Same permission model as AI Settings (admin only)
- Consistent styling with other settings panels

---

## Use Cases & Workflows

### Monthly Quality Review

1. **Admin opens Tag Quality tab** in Company Settings
2. **Reviews summary metrics** - overall acceptance/dismissal rates  
3. **Checks trust signals** - identifies problematic tag patterns
4. **Analyzes confidence correlation** - validates AI confidence accuracy
5. **Exports data** for deeper analysis or reporting
6. **Takes action** (optional):
   - Add frequently dismissed tags to block list
   - Adjust auto-apply confidence thresholds
   - Train users on valuable vs noisy AI suggestions

### Troubleshooting Low AI Value

**Scenario:** Users complain AI tags aren't useful

1. **Check acceptance rate** - is it below 30%?
2. **Review trust signals** - which specific tags are problematic?
3. **Analyze confidence correlation** - is AI overconfident?
4. **Compare time periods** - has quality degraded recently?
5. **Export detailed data** for vendor discussions

### Validating AI Settings Changes

**Before changing auto-apply settings:**
1. **Review auto-applied tag count** - current volume
2. **Check confidence bands** - which thresholds perform well
3. **Identify trust signals** - avoid auto-applying problematic tags

**After enabling auto-apply:**
1. **Monitor acceptance rates** - do they remain stable?
2. **Watch for new trust signals** - are auto-applied tags being removed?
3. **Track manual:AI ratio** - is user behavior changing?

---

## CSV Export Format

### File Structure

**Filename:** `tag-quality-metrics-{company-slug}-{time-range}.csv`
**Example:** `tag-quality-metrics-acme-corp-2026-01.csv`

**Headers:**
```
Tag,Total Generated,Accepted,Dismissed,Acceptance Rate,Dismissal Rate,
Avg Confidence,Avg Confidence (Accepted),Avg Confidence (Dismissed),Trust Signals
```

**Sample Data:**
```csv
photography,45,38,5,84.4%,11.1%,0.923,0.945,0.832,
corporate,30,8,22,26.7%,73.3%,0.885,0.912,0.871,"high_dismissal_frequency"
marketing,25,0,15,0.0%,60.0%,0.902,,,,"zero_acceptance"
```

### Use Cases for Export

- **Vendor discussions** - Share AI performance data
- **Historical trending** - Track quality over time
- **Custom analysis** - Import into Excel/BI tools
- **Executive reporting** - Monthly AI ROI summaries

---

## Validation Results

### ✅ Scope Requirements Met

**How useful AI-generated tags are:**
- ✅ Acceptance rate shows overall AI value
- ✅ Per-tag metrics identify most/least useful suggestions
- ✅ Confidence analysis validates AI self-assessment

**Which tags are accepted vs dismissed:**
- ✅ Top accepted tags list (success stories)
- ✅ Top dismissed tags list (problem areas)
- ✅ Trust signals for patterns (zero acceptance, high dismissal)

**Whether auto-applied tags are kept or removed:**
- ✅ Auto-applied tag count tracking
- ✅ Framework for retention metrics (note about removal event logging)

**How confidence correlates with acceptance:**
- ✅ Confidence band analysis with acceptance rates
- ✅ Average confidence comparison (accepted vs dismissed)
- ✅ Trust signal for confidence-acceptance mismatches

**Where AI tagging is noisy or valuable:**
- ✅ Trust signals identify problematic patterns
- ✅ Acceptance rate trends show overall value
- ✅ Per-tag breakdown shows specific value/noise sources

### ✅ Technical Constraints Satisfied

**No schema changes to locked tables:**
- ✅ Uses existing `asset_tags`, `asset_tag_candidates`, `assets` tables
- ✅ No modifications to existing columns or indexes

**New read-only views or queries allowed:**
- ✅ `TagQualityMetricsService` provides read-only analytics
- ✅ All queries are SELECT only, no mutations

**No background jobs required:**
- ✅ On-demand calculation when metrics are requested
- ✅ Caching provides performance without scheduled jobs

**No AI calls:**
- ✅ Pure analytics from existing historical data
- ✅ Zero additional AI API costs

**No policy logic duplication:**
- ✅ Uses existing `AiTagPolicyService` for AI enabled check
- ✅ No reimplementation of business rules

---

## Error Handling & Edge Cases

### UI Error States

| Condition | Display | User Actions |
|-----------|---------|--------------|
| **Loading** | Spinner with status text | Wait |
| **Permission Denied** | Yellow info box | Contact admin |
| **AI Disabled** | Gray info with settings link | Enable AI first |
| **No Data** | Blue info about no usage yet | Use AI features |
| **API Error** | Red error with retry button | Retry or check console |
| **Export Failed** | Alert dialog | Try again |

### Data Edge Cases

**Empty Result Sets:**
- No candidates in time range → Show "no data" message
- No accepted tags → Skip "most accepted" section  
- No dismissed tags → Skip "most dismissed" section
- No confidence data → Skip confidence analysis

**Invalid Data:**
- Null confidence values handled gracefully
- Division by zero prevented in rate calculations
- Missing or corrupted records filtered out
- Time range parsing errors return helpful messages

### Performance Edge Cases

**Large Data Sets:**
- Tag metrics limited to configurable count (default: 50, export: 1000)
- Confidence bands pre-aggregated (not per-record calculation)
- Trust signals computed on filtered data sets
- CSV export streams data to prevent memory issues

---

## Future Enhancements

### Advanced Analytics

**Retention Tracking:**
- Track when auto-applied tags are manually removed
- Calculate true retention rate (not just count)
- Time-to-removal analysis (immediate vs delayed removal)

**Trend Analysis:**
- Month-over-month acceptance rate trends
- Seasonal tag pattern identification  
- User behavior change detection

**Cohort Analysis:**
- Tag performance by asset type
- User acceptance patterns by role/permission
- Confidence threshold optimization recommendations

### Enhanced Trust Signals

**Pattern Detection:**
- Tags that start well but degrade over time
- Confidence calibration issues by tag category
- User fatigue patterns (increasing dismissal rates)

**Predictive Indicators:**
- Early warning for declining tag quality
- Confidence threshold recommendations
- Optimal auto-apply settings based on historical data

### Integration Enhancements

**Automated Insights:**
- Weekly/monthly summary emails for admins
- Integration with business intelligence tools
- API webhooks for external monitoring systems

---

## Summary

Phase J.2.6 successfully delivers comprehensive tag quality analytics that provide company admins with deep insights into AI tagging performance:

**Key Accomplishments:**
- ✅ **Complete visibility** into AI tag usefulness and user acceptance patterns
- ✅ **Trust signal detection** for problematic AI behaviors without automatic intervention  
- ✅ **Confidence correlation analysis** validates AI self-assessment accuracy
- ✅ **Historical trending** via time range selection and CSV export
- ✅ **Graceful degradation** handling all error and edge case scenarios

**Business Value:**
- **Data-driven decisions** about AI settings and policies
- **Quality monitoring** to ensure AI provides ongoing value
- **Problem identification** before user satisfaction declines  
- **Evidence collection** for AI model feedback and improvements

**Technical Excellence:**
- Read-only analytics with zero impact on existing AI behavior
- Tenant-scoped security with proper permission boundaries
- Performant queries with appropriate caching and limits
- Comprehensive error handling with helpful user guidance

**Status:** Ready for company admin review and feedback collection.

**Next Phase:** Awaiting approval to proceed with advanced analytics or ready for production deployment of current tag quality monitoring system.