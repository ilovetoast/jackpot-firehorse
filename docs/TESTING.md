# Testing

Pre-launch checklist and manual/automated test coverage for Jackpot. Use this doc to ensure critical flows are thoroughly tested before going live and to see what is already covered by tests.

---

## Pre-launch: User attachment and detachment

These flows must be manually and/or automatically verified before release.

### Company

- [ ] **Attach:** User invited to company → accepts → appears in team; can access company and brands per assignment.
- [ ] **Detach:** User removed from company (remove from tenant) → loses all company/brand/collection access; cannot see company in switcher.
- [ ] **Deleted then readded:** User removed from company, then re-invited and accepts → regains access with correct role and brand assignments.
- [ ] **Orphan records:** User removed from company but `brand_user` or `collection_user` rows remain → UI shows “Orphaned Record” where appropriate; “Delete from company” cleans up all relations (tenant, brand, collection) and removes orphan state.
- [ ] **Delete from company:** “Delete from company” (trash) removes user from tenant, all brand assignments, and all collection access; user no longer appears in team and has no residual access.

### Brand

- [ ] **Attach:** User added to brand (invite with brand role, or “Add to brand” for existing company member) → has correct brand role and access.
- [ ] **Detach:** User removed from brand → loses brand access; if no other brands, shows “No brand access” / “Add to brand” in team UI.
- [ ] **Deleted then readded:** User removed from brand, then added again via “Add to brand” → gets chosen role and access.
- [ ] **Orphan records:** User in `brand_user` but not in tenant `users` → appears as orphan in team list; cleanup (delete from company or remove brand assignment) clears orphan state.
- [ ] **Add to brand modal:** “Add to brand” opens with “Select a brand…”; CTA disabled until a brand is selected; submit adds user to selected brand with chosen role.

### Collection (including collection-only access)

- [ ] **Attach (full member):** User with brand access gets collection access via brand/role; can view/edit per permissions.
- [ ] **Attach (collection-only):** User invited to collection only (no brand) → accepts → has access only to that collection; nav shows “Collection access only”; Dashboard/Assets/etc. disabled; Collections enabled.
- [ ] **Detach:** User removed from collection (or collection access revoked) → loses access to that collection; if collection-only and that was last collection, loses all access to tenant context.
- [ ] **Deleted then readded:** Collection-only user removed (e.g. delete from company), then re-invited to same or another collection → can accept and access again.
- [ ] **Orphan records:** User has `collection_user` for tenant’s collections but is not in tenant `users` → appears as collection-only orphan in team list; “Delete from company” removes all collection access and cleans orphans.
- [ ] **Switch collection:** Collection-only user with multiple collections can switch active collection; nav and data reflect selected collection.

---

## Pre-launch: Metadata testing

Critical metadata behavior to verify before release.

### Workflow

- [ ] **Metadata workflow states:** Draft → in review → approved (or rejected) transitions work; status and visibility match state.
- [ ] **Required fields and validation:** Missing required metadata blocks completion or shows clear errors; invalid values are rejected with messages.
- [ ] **Schema-driven UI:** Field visibility, labels, and types follow tenant/brand metadata schema; custom fields appear and save correctly.

### Approvals

- [ ] **Assets requiring approval:** Created as unpublished; not visible in default grid until approved.
- [ ] **Approval grant → publish:** Granting approval publishes asset; visibility and “published” state update.
- [ ] **Deliverables:** Same approval and publish behavior as assets (lifecycle consistency).
- [ ] **Notifications:** Approval requests and outcomes trigger expected notifications (if applicable).

### Positioning and ordering

- [ ] **Collection asset order:** Manual reorder (e.g. drag-and-drop or position field) persists and reflects in list/grid.
- [ ] **Sort options:** Sort by date, name, custom metadata, etc. returns correct order.
- [ ] **Pagination:** Ordering is stable across pages when sorting by position or other fields.

### Editing

- [ ] **Inline edit:** Editing metadata in grid/list saves and updates without breaking layout or losing data.
- [ ] **Bulk edit:** Multi-select and bulk metadata update applies to all selected items; partial failures (if any) are reported.
- [ ] **Concurrent edit:** Two users editing same asset/metadata; last write or conflict handling behaves as designed.
- [ ] **History/audit:** Metadata changes (and approval events) are recorded for audit where required.

---

## Current automated tests

Below are the test files in the repo. Run from project root:

```bash
# All tests
./vendor/bin/sail test
# Or
php artisan test

# Feature only
php artisan test tests/Feature

# Unit only
php artisan test tests/Unit
```

### Feature tests

| Test file | Area |
|-----------|------|
| `OrphanAndDeleteFromCompanyTest.php` | Orphan detection, delete-from-company cleanup |
| `CollectionAccessC12Test.php` | Collection-only access (C12) |
| `CollectionEditC111Test.php` | Collection editing (C11.1) |
| `CollectionInviteTest.php` | Collection invitations |
| `CollectionSignalsC11Test.php` | Collection signals (C11) |
| `PublicCollectionTest.php` | Public collection access |
| `CollectionCreateTest.php` | Collection creation |
| `CollectionPolicyTest.php` | Collection policies |
| `CollectionPersistenceTest.php` | Collection persistence |
| `CollectionAddAssetTest.php` | Adding assets to collections |
| `CollectionRemoveAssetTest.php` | Removing assets from collections |
| `CollectionInlineAttachTest.php` | Inline attach to collection |
| `CollectionInlineCreateAuthorizationTest.php` | Inline create collection auth |
| `CollectionFieldVisibilityTest.php` | Collection field visibility |
| `CollectionVisibilityTest.php` | Collection visibility rules |
| `CollectionAssetQueryTest.php` | Collection asset queries |
| `CollectionUploadFinalizeTest.php` | Collection upload finalize |
| `CollectionUploadRealWorldTest.php` | Collection upload real-world |
| `CollectionsControllerTest.php` | Collections controller |
| `CategoryScopedVisibilityTest.php` | Category-scoped visibility |
| `CategoryScopedVisibilityErrorHandlingTest.php` | Category visibility errors |
| `ApprovalFlowTest.php` | Approval → publish → visibility |
| `AssetVisibilityApprovalTest.php` | Asset visibility and approval |
| `AssetDeliverableLifecycleConsistencyTest.php` | Asset vs deliverable lifecycle |
| `AssetExpirationTest.php` | Asset expiration |
| `AssetTagApiTest.php` | Asset tag API |
| `IsPublishedFlagTest.php` | Published flag behavior |
| `PipelineSequencingTest.php` | Pipeline sequencing |
| `ProcessingPipelineHealthTest.php` | Processing pipeline health |
| `TagsMetadataFieldTest.php` | Tags as metadata field |
| `TagNormalizationIntegrationTest.php` | Tag normalization |
| `TagQualityMetricsTest.php` | Tag quality metrics |
| `TagUIConsistencyTest.php` | Tag UI consistency |
| `UploadCompletionMetadataPersistenceTest.php` | Upload completion metadata |
| `SystemAutomatedFilterSchemaTest.php` | Automated filter schema |
| `AiMetadataRegenerationTest.php` | AI metadata regeneration |
| `AiTaggingPipelineTest.php` | AI tagging pipeline |
| `AiTagAutoApplyIntegrationTest.php` | AI tag auto-apply |
| `AiSuggestionDispatchTest.php` | AI suggestion dispatch |
| `CompanyAiSettingsTest.php` | Company AI settings |
| `DominantColorGenerationTest.php` | Dominant color generation |
| `DominantColorPersistenceTest.php` | Dominant color persistence |
| `DominantColorsGenerationForNewAssetTest.php` | Dominant color for new asset |
| `DominantColorBucketSwatchTest.php` | Dominant color bucket swatch |
| `AvifDominantColorExtractionTest.php` | AVIF dominant color extraction |
| `ThumbnailFailureStateTest.php` | Thumbnail failure state |
| `Feature/Jobs/AiMetadataGenerationJobTest.php` | AI metadata generation job |
| `Feature/Uploads/MultipartUploadPresignedUrlTest.php` | Multipart upload presigned URL |
| `ExampleTest.php` | Example feature test |

### Unit tests

| Test file | Area |
|-----------|------|
| `AssetStatusContractTest.php` | Asset status contract |
| `AlertLifecycleTest.php` | Alert lifecycle |
| `Unit/Services/ComputedMetadataServiceTest.php` | Computed metadata service |
| `Unit/Services/MetadataSchemaResolverTest.php` | Metadata schema resolver |
| `Unit/Services/MetadataVisibilityResolverTest.php` | Metadata visibility resolver |
| `Unit/Services/TenantMetadataFieldServiceTest.php` | Tenant metadata field service |
| `Unit/Services/UploadMetadataSchemaResolverTest.php` | Upload metadata schema resolver |
| `Unit/Services/UploadCompletionApprovalTest.php` | Upload completion approval |
| `Unit/Services/AiMetadataGenerationServiceTest.php` | AI metadata generation service |
| `Unit/Services/AiMetadataConfidenceServiceTest.php` | AI metadata confidence |
| `Unit/Services/AiMetadataSuggestionServiceTest.php` | AI metadata suggestion service |
| `Unit/Services/AiTagPolicyServiceTest.php` | AI tag policy service |
| `Unit/Services/TagNormalizationServiceTest.php` | Tag normalization service |
| `Unit/Services/AssetArchiveServiceTest.php` | Asset archive service |
| `Unit/Services/AssetPublicationServiceTest.php` | Asset publication service |
| `Unit/Services/PatternDetectionServiceTest.php` | Pattern detection service |
| `Unit/Services/AutoTicketCreationServiceTest.php` | Auto ticket creation |
| `Unit/Services/Automation/DominantColorsExtractorTest.php` | Dominant colors extractor |
| `Unit/Services/Automation/ColorAnalysisServiceTest.php` | Color analysis service |
| `Unit/Listeners/SendAssetPendingApprovalNotificationTest.php` | Pending approval notification |
| `Unit/Jobs/ProcessAssetJobStatusTest.php` | Process asset job status |
| `Unit/Jobs/AggregateEventsJobTest.php` | Aggregate events job |
| `Unit/Jobs/NoJobMutatesAssetStatusTest.php` | No job mutates asset status |
| `Unit/ExampleTest.php` | Example unit test |

---

*Update this doc when adding new pre-launch checklists or test files.*
