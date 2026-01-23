🎛 Metadata Management UI Rules (Locked)
Where Primary Can Be Set

✅ By Category view only

❌ Not global

❌ Not per-asset

❌ Not in the Asset Grid UI

Toggle Rules

Label: “Primary (for this category)”

Toggle only enabled when:

Field is enabled

Field is filterable

Toggle persists to:

metadata_field_visibility.is_primary

🧩 Asset Grid Rendering Rules (Locked)
Primary Filters

Rendered by:

AssetGridMetadataPrimaryFilters


Criteria:

field.is_primary === true

Passes visibility rules:

Category compatibility

Asset type compatibility

Has available values

Secondary Filters

Rendered by:

AssetGridSecondaryFilters


Criteria:

field.is_primary !== true (false, null, undefined)

Passes visibility rules

Defensive default:
Missing is_primary → Secondary

Explicit Exclusions

The Asset Grid filter UI must never include:

Category selectors

Asset type selectors

Brand selectors

Navigation controls of any kind

Navigation is handled only by:

Sidebar

Route

Page context

👁 Visibility Rules (Locked)

A filter is hidden only if:

Not enabled for the category

Not compatible with asset type

Has zero available values in the current grid

Filters are never hidden due to:

Primary/secondary placement

Missing overrides

UI state

🛡 Defensive Guarantees

Secondary filters always render when valid

Missing data never crashes rendering

Legacy data continues to work

Phase H helpers must not be re-implemented elsewhere

🚫 Forbidden Changes Without New Phase

The following require a new phase:

Changing where is_primary is stored

Adding global primary behavior

Letting the Asset Grid infer placement

Mixing navigation controls into filter UI

Re-introducing modal-based filter panels

🔗 Future Phases That May Build on Phase H

Allowed extensions (non-breaking):

Phase J — Saved Filters

Role-based defaults

User-level filter presets

Filter ordering within primary bar

Max primary count per category

All must consume, not modify, Phase H behavior.

✅ Final Statement

Phase H is complete, validated, and locked.

All future work must respect this architecture.
Any refactor that violates these rules is considered a regression.