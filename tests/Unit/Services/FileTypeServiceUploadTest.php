<?php

namespace Tests\Unit\Services;

use App\Services\FileTypeService;
use Tests\TestCase;

/**
 * Unit coverage for the upload-allowlist surface of FileTypeService.
 *
 * The registry (config/file_types.php) is the single source of truth that
 * preflight, initiate-batch, and finalize all consult through this service.
 * If you change the registry, make sure these expectations still match —
 * they encode the contract.
 */
class FileTypeServiceUploadTest extends TestCase
{
    protected FileTypeService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(FileTypeService::class);
    }

    public function test_allows_jpeg_with_canonical_mime(): void
    {
        $decision = $this->svc->isUploadAllowed('image/jpeg', 'jpg');

        $this->assertTrue($decision['allowed']);
        $this->assertSame('ok', $decision['code']);
        $this->assertSame('image', $decision['file_type']);
    }

    public function test_blocks_exe_by_extension(): void
    {
        $decision = $this->svc->isUploadAllowed('application/octet-stream', 'exe');

        $this->assertFalse($decision['allowed']);
        $this->assertSame('blocked_executable', $decision['code']);
        $this->assertSame('executable', $decision['blocked_group']);
        $this->assertSame('warning', $decision['log_severity']);
    }

    public function test_blocks_php_server_script(): void
    {
        $decision = $this->svc->isUploadAllowed('text/x-php', 'php');

        $this->assertFalse($decision['allowed']);
        $this->assertSame('blocked_server_script', $decision['code']);
    }

    public function test_blocks_zip_archive(): void
    {
        $decision = $this->svc->isUploadAllowed('application/zip', 'zip');

        $this->assertFalse($decision['allowed']);
        $this->assertSame('blocked_archive', $decision['code']);
    }

    public function test_blocks_html_web_file(): void
    {
        $decision = $this->svc->isUploadAllowed('text/html', 'html');

        $this->assertFalse($decision['allowed']);
        $this->assertSame('blocked_web', $decision['code']);
    }

    public function test_unknown_extension_is_unsupported(): void
    {
        $decision = $this->svc->isUploadAllowed('application/x-unknown', 'asdf');

        $this->assertFalse($decision['allowed']);
        $this->assertSame('unsupported_type', $decision['code']);
    }

    public function test_blocked_takes_priority_when_extension_collides_with_allowed_mime(): void
    {
        // `.bat` is in blocked.executable.extensions; even if a benign MIME is sent,
        // the extension-based block must win.
        $decision = $this->svc->isUploadAllowed('text/plain', 'bat');

        $this->assertFalse($decision['allowed']);
        $this->assertSame('blocked_executable', $decision['code']);
    }

    public function test_sanitize_filename_strips_control_chars_and_path_traversal(): void
    {
        $this->assertSame('photo.jpg', $this->svc->sanitizeFilename("photo\x00.jpg"));
        // path traversal: leading `../` is stripped by the trim+normalize step
        $this->assertSame('photo.jpg', $this->svc->sanitizeFilename('../photo.jpg'));
        // slashes are removed entirely (no segment is preserved); intent is that
        // the user's bytes never participate in path resolution
        $this->assertStringNotContainsString('/', $this->svc->sanitizeFilename('a/b/photo.jpg'));
        // double-dots collapsed to a single dot
        $this->assertSame('photo.jpg', $this->svc->sanitizeFilename('photo..jpg'));
    }

    public function test_sanitize_filename_rejects_windows_reserved_names(): void
    {
        $this->assertSame('', $this->svc->sanitizeFilename('CON'));
        $this->assertSame('', $this->svc->sanitizeFilename('NUL'));
        $this->assertSame('', $this->svc->sanitizeFilename('COM1'));
    }

    public function test_sanitize_filename_caps_length(): void
    {
        $stem = str_repeat('a', 250);
        $name = $stem.'.jpg';
        $sanitized = $this->svc->sanitizeFilename($name);

        $this->assertLessThanOrEqual(200, mb_strlen($sanitized));
        $this->assertStringEndsWith('.jpg', $sanitized);
    }

    public function test_detect_double_extension_attack(): void
    {
        $hit = $this->svc->detectDoubleExtensionAttack('evil.php.jpg');

        $this->assertNotNull($hit);
        $this->assertSame('php', $hit['hit_extension']);
        $this->assertSame('server_script', $hit['group']);
    }

    public function test_detect_double_extension_attack_passes_clean_filename(): void
    {
        $this->assertNull($this->svc->detectDoubleExtensionAttack('photo.jpg'));
        // Single benign segments only; archives are intentionally on the blocked list
        // so `.tar.gz` correctly trips the gate (tarballs are not allowed for upload).
        $this->assertNull($this->svc->detectDoubleExtensionAttack('image.preview.png'));
    }

    public function test_canonicalize_sniffed_mime_uses_aliases(): void
    {
        // `audio/x-mpeg` aliases to `audio/mpeg` per file_types.php → audio.upload.sniff_mime_aliases
        $this->assertSame('audio/mpeg', $this->svc->canonicalizeSniffedMime('audio/x-mpeg', 'mp3'));
        $this->assertSame('image/jpeg', $this->svc->canonicalizeSniffedMime('image/jpeg', 'jpg'));
    }

    public function test_requires_sanitization_only_for_svg(): void
    {
        $this->assertTrue($this->svc->requiresSanitization('svg'));
        $this->assertFalse($this->svc->requiresSanitization('image'));
        $this->assertFalse($this->svc->requiresSanitization('audio'));
    }

    public function test_matches_registry_type_for_office_xlsx(): void
    {
        $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        $this->assertTrue($this->svc->matchesRegistryType($mime, null, 'office'));
        $this->assertTrue($this->svc->matchesRegistryType(null, 'xlsx', 'office'));
        $this->assertTrue($this->svc->isOfficeDocument($mime, 'xlsx'));
        $this->assertFalse($this->svc->isOfficeDocument('image/png', 'png'));
    }

    public function test_allows_txt_plaintext(): void
    {
        $decision = $this->svc->isUploadAllowed('text/plain', 'txt');

        $this->assertTrue($decision['allowed']);
        $this->assertSame('ok', $decision['code']);
        $this->assertSame('plaintext', $decision['file_type']);
    }

    public function test_allows_csv_plaintext(): void
    {
        $decision = $this->svc->isUploadAllowed('text/csv', 'csv');

        $this->assertTrue($decision['allowed']);
        $this->assertSame('ok', $decision['code']);
        $this->assertSame('plaintext', $decision['file_type']);
    }

    public function test_detect_file_type_plaintext_by_mime_and_extension(): void
    {
        $this->assertSame('plaintext', $this->svc->detectFileType('text/plain', 'txt'));
        $this->assertSame('plaintext', $this->svc->detectFileType('application/csv', 'csv'));
        $this->assertSame('plaintext', $this->svc->detectFileType('text/plain', null));
        $this->assertSame('svg', $this->svc->detectFileType('text/plain', 'svg'));
    }

    public function test_allows_indesign_indd_and_idml(): void
    {
        $indd = $this->svc->isUploadAllowed('application/x-adobe-indesign', 'indd');
        $this->assertTrue($indd['allowed']);
        $this->assertSame('ok', $indd['code']);
        $this->assertSame('indesign', $indd['file_type']);

        $idml = $this->svc->isUploadAllowed('application/vnd.adobe.indesign-idml', 'idml');
        $this->assertTrue($idml['allowed']);
        $this->assertSame('ok', $idml['code']);
        $this->assertSame('indesign', $idml['file_type']);
    }

    public function test_detect_file_type_indesign_by_extension_when_mime_is_generic(): void
    {
        $this->assertSame('indesign', $this->svc->detectFileType('application/octet-stream', 'indd'));
        $this->assertSame('indesign', $this->svc->detectFileType('application/x-adobe-indesign', 'indd'));
    }

    public function test_indesign_registry_thumbnail_pipeline_when_imagick_and_gd_available(): void
    {
        if (! extension_loaded('imagick') || ! extension_loaded('gd')) {
            $this->markTestSkipped('Requires imagick and gd for InDesign thumbnails');
        }

        $this->assertTrue($this->svc->registryTypeSupportsThumbnailPipeline('indesign'));
        $this->assertSame('generateIndesignThumbnail', $this->svc->getHandler('indesign', 'thumbnail'));
    }

    public function test_matches_registry_type_for_pdf(): void
    {
        $this->assertTrue($this->svc->matchesRegistryType('application/pdf', 'pdf', 'pdf'));
        $this->assertFalse($this->svc->matchesRegistryType('application/pdf', 'pdf', 'office'));
    }

    public function test_get_upload_registry_for_frontend_includes_registry_reference(): void
    {
        $payload = $this->svc->getUploadRegistryForFrontend();
        $this->assertArrayHasKey('registry_reference', $payload);
        $this->assertSame('config/file_types.php', $payload['registry_reference']['canonical_config']);
        $this->assertStringContainsString('PRODUCTION_WORKER_SOFTWARE.md', $payload['registry_reference']['worker_preview_doc']);
    }
}
