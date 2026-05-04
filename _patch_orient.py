from pathlib import Path
import re

path = Path("app/Services/ImageOrientationNormalizer.php")
text = path.read_text()

old = r"        $rotateCcw = function (\GdImage $g, float $angleCcw) use (&$rotateCcw): \GdImage|false {"
new = r"        $rotateCcw = function (\GdImage $g, float $angleCcw): \GdImage|false {"
if old not in text:
    raise SystemExit("rotateCcw pattern not found")
text = text.replace(old, new, 1)

old_doc = """     * When Imagick is available, uses autoOrient + TOPLEFT + strip (preferred). On failure or
     * missing Imagick, returns the original path and type for legacy GD decode (JPEG EXIF may be ignored).
"""
new_doc = """     * When Imagick is available, uses autoOrient + TOPLEFT + strip (preferred). If Imagick fails,
     * auto-orient did not run, or swap orientations (5–8) still match raw dimensions, JPEGs fall back to
     * a GD+EXIF bake into a flat PNG. Otherwise returns the original path for legacy GD decode.
"""
if old_doc not in text:
    raise SystemExit("docblock not found")
text = text.replace(old_doc, new_doc, 1)

pattern = r"""        if \(! extension_loaded\('imagick'\) \|\| ! is_readable\(\$sourcePath\)\) \{
            \$type = is_array\(\$info\) \? \(int\) \$info\[2\] : IMAGETYPE_JPEG;

            return \[
                'path' => \$sourcePath,
                'cleanup' => false,
                'imagetype' => \$type,
                'profile' => \$baseProfile,
            \];
        \}

        \$m = null;
        try \{
            \$m = new \\Imagick;
            \$m->readImage\(\$sourcePath\);
            if \(\$m->getNumberImages\(\) > 1\) \{
                \$m->setIteratorIndex\(0\);
                \$first = \$m->getImage\(\);
                \$m->clear\(\);
                \$m->destroy\(\);
                \$m = \$first;
            \}

            \$diag = self::imagickAutoOrientAndResetOrientation\(\$m\);
            \$m->setImageFormat\('png'\);
            \$m->setImageCompressionQuality\(100\);
            \$tmp = tempnam\(sys_get_temp_dir\(\), 'jp_orient_'\)\.'\.png';
            if \(! \$m->writeImage\(\$tmp\)\) \{
                \$m->clear\(\);
                \$m->destroy\(\);
                \$m = null;

                throw new \\RuntimeException\('writeImage failed'\);
            \}
            \$m->clear\(\);
            \$m->destroy\(\);
            \$m = null;

            \$profile = array_merge\(\$baseProfile, \$diag, \[
                'pipeline' => 'imagick_flat_png_for_gd',
                'method' => 'imagick_flat_png',
                'auto_orient_applied' => \$diag\['applied'\] \|\| \(\$diag\['width_before'\] !== \$diag\['width_after'\] \|\| \$diag\['height_before'\] !== \$diag\['height_after'\]\),
            \]\);

            return \[
                'path' => \$tmp,
                'cleanup' => true,
                'imagetype' => IMAGETYPE_PNG,
                'profile' => \$profile,
            \];
        \} catch \(\\Throwable\) \{
            if \(\$m instanceof \\Imagick\) \{
                try \{
                    \$m->clear\(\);
                    \$m->destroy\(\);
                \} catch \(\\Throwable\) \{
                \}
            \}
            \$type = is_array\(\$info\) \? \(int\) \$info\[2\] : IMAGETYPE_JPEG;

            return \[
                'path' => \$sourcePath,
                'cleanup' => false,
                'imagetype' => \$type,
                'profile' => array_merge\(\$baseProfile, \[
                    'method' => 'gd_raw_path_imagick_failed',
                \]\),
            \];
        \}"""

new_block = """        if (! extension_loaded('imagick') || ! is_readable($sourcePath)) {
            $gdFlat = self::tryGdExifFlatPng($sourcePath, $exifTag, $baseProfile);
            if ($gdFlat !== null) {
                return $gdFlat;
            }
            $type = is_array($info) ? (int) $info[2] : IMAGETYPE_JPEG;

            return [
                'path' => $sourcePath,
                'cleanup' => false,
                'imagetype' => $type,
                'profile' => $baseProfile,
            ];
        }

        $m = null;
        try {
            $m = new \\Imagick;
            $m->readImage($sourcePath);
            if ($m->getNumberImages() > 1) {
                $m->setIteratorIndex(0);
                $first = $m->getImage();
                $m->clear();
                $m->destroy();
                $m = $first;
            }

            $diag = self::imagickAutoOrientAndResetOrientation($m);
            $m->setImageFormat('png');
            $m->setImageCompressionQuality(100);
            $tmp = tempnam(sys_get_temp_dir(), 'jp_orient_').'.png';
            if (! $m->writeImage($tmp)) {
                $m->clear();
                $m->destroy();
                $m = null;

                throw new \\RuntimeException('writeImage failed');
            }
            $m->clear();
            $m->destroy();
            $m = null;

            $flatInfo = @getimagesize($tmp);
            if (self::imagickFlatLikelyWrong($exifTag, $info, $flatInfo, $diag)) {
                $gdFlat = self::tryGdExifFlatPng($sourcePath, $exifTag, $baseProfile);
                if ($gdFlat !== null) {
                    @unlink($tmp);

                    return $gdFlat;
                }
            }

            $profile = array_merge($baseProfile, $diag, [
                'pipeline' => 'imagick_flat_png_for_gd',
                'method' => 'imagick_flat_png',
                'auto_orient_applied' => $diag['applied'] || ($diag['width_before'] !== $diag['width_after'] || $diag['height_before'] !== $diag['height_after']),
            ]);

            return [
                'path' => $tmp,
                'cleanup' => true,
                'imagetype' => IMAGETYPE_PNG,
                'profile' => $profile,
            ];
        } catch (\\Throwable) {
            if ($m instanceof \\Imagick) {
                try {
                    $m->clear();
                    $m->destroy();
                } catch (\\Throwable) {
                }
            }
            $gdFlat = self::tryGdExifFlatPng($sourcePath, $exifTag, $baseProfile);
            if ($gdFlat !== null) {
                return $gdFlat;
            }
            $type = is_array($info) ? (int) $info[2] : IMAGETYPE_JPEG;

            return [
                'path' => $sourcePath,
                'cleanup' => false,
                'imagetype' => $type,
                'profile' => array_merge($baseProfile, [
                    'method' => 'gd_raw_path_imagick_failed',
                ]),
            ];
        }"""

m = re.search(pattern, text, re.DOTALL)
if not m:
    raise SystemExit("regex block not found")
text = text[: m.start()] + new_block + text[m.end() :]
path.write_text(text)
print("ok")
