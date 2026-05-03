# EXIF orientation fixtures

Automated tests in `tests/Unit/Services/ThumbnailImageOrientationTest.php` synthesize JPEGs with **Imagick** (orientation tag 6, “rotate 90 CW” for display) so CI does not rely on binary blobs.

To add static fixtures later, export from a camera or use `exiftool` on a small baseline JPEG, then document the tool version and command here.
