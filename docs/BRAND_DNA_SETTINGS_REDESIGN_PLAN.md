# Brand DNA Settings Page Redesign Plan

## Vision

The Brand DNA settings page is the **backend power user spot** — a place to view versions, preview guidelines, create new versions, upload files, edit directly, or re-run the builder. It should mirror the wizard builder structure and reflect both the website (AI research) and brand guidelines (PDF/materials) workflows.

---

## 1. Top-Level Layout (Above the Tabs)

### A. AI Brand Research (Website Analysis)
- **Placement:** Above the tabs, in its own card/section
- **Purpose:** Analyze a website URL and propose Brand DNA changes
- **Content:**
  - Website URL input
  - "Run AI Brand Research" button
  - Short description: "Analyzes a website and proposes a new Brand DNA draft. Your active Brand DNA will not change automatically."
- **Concept:** This is the "website" side of the Background step — crawling and extracting insights from a URL

### B. Brand Guidelines Upload
- **Placement:** Above the tabs, next to or below AI Research
- **Purpose:** Upload PDF guidelines and brand materials directly from settings (without going through the wizard)
- **Content:**
  - PDF guidelines dropzone (same as Builder Background step)
  - Brand materials upload / "Select from Assets"
  - Triggers ingestion when files are added
- **Concept:** This is the "brand guidelines" side — PDF + materials that get extracted and applied to Brand DNA

### C. Actions Bar (Create / Builder)
- **"Create new brand guidelines"** (rename from "Run Builder Again")
  - Opens the wizard builder
  - Creates a new draft if none exists, or resumes existing draft
- **"Create Draft Version"** — creates an empty draft from the active version (or blank)
- **"Activate Draft"** — when viewing a draft, activate it to make it live

---

## 2. Versions Section Redesign

### Current Problem
- Versions show as a dropdown with "v1 (draft) 3/2/2026" — no context, no preview
- User cannot tell what each version contains or where it came from

### Proposed: Version List with Context & Preview

**Version list (replace dropdown):**
- Each version as a card or row with:
  - **Version label:** v5 (draft) or v4 (active)
  - **Date created**
  - **Source:** "Manual", "Builder", "AI Research", "PDF import"
  - **Summary:** 1–2 line preview (e.g. "Archetype: Creator • Purpose: Mission X • Tagline: Y")
  - **Actions:** Preview | Edit | Activate (if draft)

**Preview:**
- "Preview" opens a modal or side panel showing the guidelines as they would appear on the public Brand Guidelines page
- Reuse the same layout as `Brands/BrandGuidelines/Index` (read-only render)
- Or link to `/brands/{id}/guidelines?version={versionId}` for full-page preview

**Version metadata to surface:**
- `source_type` (manual, builder, ai_research)
- Whether it has PDF/materials attached (from `brand_model_version_assets`)
- Last modified / created date

---

## 3. Tab Alignment with Builder Steps

**Builder step order:**
1. Background
2. Archetype
3. Purpose
4. Expression
5. Positioning
6. Standards

**Proposed Settings tabs (match builder):**

| Tab | Builder Step | Content |
|-----|--------------|---------|
| **Background** | Background | Website URL, Social URLs, PDF status, Brand materials (read-only summary + link to upload above) |
| **Archetype** | Archetype | Primary archetype, candidate archetypes |
| **Purpose** | Purpose | Mission, Positioning (Why/What) |
| **Expression** | Brand Expression | Brand Look, Brand Voice, Tone, Traits |
| **Positioning** | Positioning | Industry, Target Audience, Beliefs, Values, Tagline, Competitive Position |
| **Standards** | Standards | Colors, Typography, Photography style, Scoring rules |

**Mapping from current Settings:**
- **Identity** → split into **Purpose** (mission, positioning) and **Positioning** (industry, audience, tagline, beliefs, values)
- **Personality** → split into **Archetype** and **Expression**
- **Visual** + **Typography** + **Scoring Rules** → merge into **Standards** (or keep as sub-sections within Standards)

---

## 4. Page Structure (Wireframe)

```
┌─────────────────────────────────────────────────────────────────┐
│ ← Back to Brand Settings                    [Enabled toggle]     │
├─────────────────────────────────────────────────────────────────┤
│ Brand DNA — {Brand Name}                                         │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│ ┌─ AI Brand Research ──────────────────────────────────────┐   │
│ │ Website URL: [________________] [Run AI Brand Research]    │   │
│ │ Analyzes website and proposes a new Brand DNA draft.       │   │
│ └────────────────────────────────────────────────────────────┘   │
│                                                                  │
│ ┌─ Brand Guidelines ────────────────────────────────────────┐   │
│ │ [Upload PDF guidelines]  or  [Select from Assets]          │   │
│ │ Last uploaded: guidelines.pdf • 2 materials                │   │
│ └────────────────────────────────────────────────────────────┘   │
│                                                                  │
│ ┌─ Versions ─────────────────────────────────────────────────┐   │
│ │ Active: v4                                                   │   │
│ │ ┌─────────────────────────────────────────────────────────┐ │   │
│ │ │ v5 (draft) 3/2/2026 • Builder • [Preview] [Edit] [Activate]│ │   │
│ │ │ v4 (active) 3/1/2026 • Manual • [Preview] [Edit]          │ │   │
│ │ │ v3 (draft) 2/28/2026 • AI Research • [Preview] [Edit]    │ │   │
│ │ └─────────────────────────────────────────────────────────┘ │   │
│ │ [Create Draft Version]  [Create new brand guidelines]        │   │
│ └────────────────────────────────────────────────────────────┘   │
│                                                                  │
│ ┌─ Tabs (aligned with Builder) ──────────────────────────────┐   │
│ │ Background | Archetype | Purpose | Expression | Positioning | Standards │
│ ├────────────────────────────────────────────────────────────┤   │
│ │                                                             │   │
│ │  [Tab content — editable fields for selected version]      │   │
│ │                                                             │   │
│ └────────────────────────────────────────────────────────────┘   │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 5. Implementation Phases

### Phase 1: Nomenclature & Quick Wins
- [ ] Rename "Run Builder Again" → "Create new brand guidelines"
- [ ] Move AI Brand Research and Brand Guidelines upload above the version bar
- [ ] Add version summary (1-line) to each version in the dropdown

### Phase 2: Tab Alignment
- [ ] Reorganize tabs to match builder: Background, Archetype, Purpose, Expression, Positioning, Standards
- [ ] Map existing Identity/Personality/Visual/Typography/Scoring content into new tabs
- [ ] Add Background tab (sources summary, link to upload area)

### Phase 3: Versions List & Preview
- [ ] Replace version dropdown with a list/card view
- [ ] Add version metadata (source_type, date, summary)
- [ ] Add "Preview" action → open guidelines preview (reuse Brands/BrandGuidelines/Index layout)
- [ ] Add preview route: `GET /brands/{brand}/guidelines?version={id}` (read-only, specific version)

### Phase 4: Brand Guidelines Upload in Settings
- [ ] Add PDF dropzone and brand materials selector to the top section
- [ ] Wire to same attach/ingestion flow as Builder
- [ ] Show "Last uploaded" summary when assets exist

### Phase 5: Polish
- [ ] Ensure "Create new brand guidelines" resumes draft or creates new (already done)
- [ ] Add "Start over" in Builder (already done)
- [ ] Cross-link: from Settings → Builder, from Builder → Settings (Back to Brand Settings)

---

## 6. Data Model Notes

- **BrandModelVersion** has `source_type` (manual, builder, etc.) — use for version labels
- **BrandModelVersionAsset** links assets (PDF, materials) to versions via `builder_context`
- **BrandGuidelines/Index** renders from `activeVersion.model_payload` — extend to accept `?version=` for preview

---

## 7. User Flows

| User Goal | Flow |
|-----------|------|
| View current guidelines | Settings → select active version → Preview |
| Edit Brand DNA directly | Settings → select version → edit in tabs → Save |
| Create new version via wizard | Settings → Create new brand guidelines → Builder |
| Upload PDF/materials | Settings → Brand Guidelines section → upload |
| Run AI research on website | Settings → AI Brand Research → enter URL → Run |
| Preview a draft before publishing | Settings → select draft → Preview |

---

## 8. Nomenclature Summary

| Old | New |
|-----|-----|
| Run Builder Again | Create new brand guidelines |
| Run AI Brand Research | Run AI Brand Research (keep; move placement) |
| Select version (dropdown) | Versions list with Preview / Edit / Activate |
| Identity | Purpose + Positioning |
| Personality | Archetype + Expression |
| Typography, Visual, Scoring Rules | Standards (combined tab) |
