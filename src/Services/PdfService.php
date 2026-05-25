<?php
declare(strict_types=1);

namespace Oasebos\Participations\Services;

final class PdfService
{
    public function generateParticipationPdf(array $participation, string $certificateHtml, string $agreementHtml, string $css = ''): ?string
    {
        $upload = wp_upload_dir(null, false);
        if (! empty($upload['error'])) { return null; }
        $dir = trailingslashit($upload['basedir']) . 'oasebos-participations/pdfs/' . gmdate('Y/m');
        wp_mkdir_p($dir);
        if (! is_dir($dir) || ! is_writable($dir)) { return null; }
        $safeNumber = sanitize_file_name($participation['participation_number'] ?? ('participation-' . time()));
        $path = $dir . '/' . $safeNumber . '-' . wp_generate_password(12, false, false) . '.pdf';
        $html = '<html><head><meta charset="utf-8"><style>' . $css . $this->dompdfCertificateCss() . '.oasebos-page-break{page-break-before:always}</style></head><body><section class="certificate">' . $this->normalizeCertificateImages($certificateHtml) . '</section><section class="oasebos-page-break agreement">' . $agreementHtml . '</section></body></html>';
        if (class_exists('\Dompdf\Dompdf')) {
            $dompdf = $this->createDompdf($upload);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4');
            $dompdf->render();
            return false !== file_put_contents($path, $dompdf->output()) ? $path : null;
        }
        // Graceful fallback: store renderable HTML with .html extension and return it for admin diagnosis.
        $fallback = preg_replace('/\.pdf$/', '.html', $path);
        return false !== file_put_contents($fallback, $html) ? $fallback : null;
    }

    private function createDompdf(array $upload): \Dompdf\Dompdf
    {
        $tmpDir = trailingslashit((string) ($upload['basedir'] ?? sys_get_temp_dir())) . 'oasebos-participations/dompdf-tmp';
        wp_mkdir_p($tmpDir);

        $options = new \Dompdf\Options();
        $options->setIsRemoteEnabled(true);
        $options->setTempDir(is_dir($tmpDir) && is_writable($tmpDir) ? $tmpDir : sys_get_temp_dir());
        $options->setChroot(array_filter([
            ABSPATH,
            $upload['basedir'] ?? null,
            WP_CONTENT_DIR,
        ]));

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->setBasePath(ABSPATH);
        return $dompdf;
    }

    private function normalizeCertificateImages(string $html): string
    {
        $html = $this->inlineLocalImages($html);
        $html = $this->normalizeImageBox($html, 'signature-placeholder', '58mm', '16mm');
        $html = $this->normalizeImageBox($html, 'mark-placeholder anbi', '18mm', '15mm');
        $html = $this->normalizeImageBox($html, 'mark-placeholder cbf', '34mm', '15mm');
        $html = preg_replace('/<div class="quality-marks">\s*(<div class="mark-placeholder anbi has-image">[\s\S]*?<\/div>)\s*(<div class="mark-placeholder cbf has-image">[\s\S]*?<\/div>)\s*<\/div>/', '<table class="quality-marks-table" cellpadding="0" cellspacing="0"><tr><td class="quality-mark-cell anbi-cell">$1</td><td class="quality-mark-spacer"></td><td class="quality-mark-cell cbf-cell">$2</td></tr></table>', $html) ?? $html;
        return $html;
    }

    private function inlineLocalImages(string $html): string
    {
        $upload = wp_upload_dir(null, false);
        $baseUrl = isset($upload['baseurl']) ? rtrim((string) $upload['baseurl'], '/') : '';
        $baseDir = isset($upload['basedir']) ? rtrim((string) $upload['basedir'], '/') : '';
        $siteUrl = rtrim(site_url(), '/');
        $abspath = rtrim(ABSPATH, '/');

        return preg_replace_callback('/<img\b([^>]*?)\bsrc\s*=\s*(["\'])(.*?)\2([^>]*)>/i', static function (array $matches) use ($baseUrl, $baseDir, $siteUrl, $abspath): string {
            $src = html_entity_decode($matches[3], ENT_QUOTES);
            if (str_starts_with($src, 'data:')) {
                return $matches[0];
            }

            $path = '';
            if ($baseUrl && $baseDir && str_starts_with($src, $baseUrl)) {
                $path = $baseDir . substr($src, strlen($baseUrl));
            } elseif (str_starts_with($src, $siteUrl)) {
                $path = $abspath . substr($src, strlen($siteUrl));
            } else {
                $urlPath = (string) (wp_parse_url($src, PHP_URL_PATH) ?: '');
                $uploadPath = $baseUrl ? (string) (wp_parse_url($baseUrl, PHP_URL_PATH) ?: '') : '';
                if ($urlPath && $uploadPath && $baseDir && str_starts_with($urlPath, $uploadPath)) {
                    $path = $baseDir . substr($urlPath, strlen($uploadPath));
                } elseif ($urlPath && str_starts_with($urlPath, '/wp-content/')) {
                    $path = $abspath . $urlPath;
                }
            }

            $data = false;
            $mime = '';
            if ($path && is_readable($path)) {
                $mime = wp_check_filetype($path)['type'] ?: 'image/png';
                $data = file_get_contents($path);
            }

            if (false === $data) {
                $attachmentId = attachment_url_to_postid($src);
                $attachmentPath = $attachmentId ? get_attached_file($attachmentId) : '';
                if ($attachmentPath && is_readable($attachmentPath)) {
                    $mime = get_post_mime_type($attachmentId) ?: (wp_check_filetype($attachmentPath)['type'] ?: 'image/png');
                    $data = file_get_contents($attachmentPath);
                }
            }

            if (false === $data) {
                $response = wp_remote_get($src, ['timeout' => 10, 'sslverify' => false]);
                if (! is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
                    $mime = wp_remote_retrieve_header($response, 'content-type') ?: 'image/png';
                    $data = wp_remote_retrieve_body($response);
                }
            }

            if (false === $data) {
                return $matches[0];
            }

            return '<img' . $matches[1] . 'src="data:' . esc_attr((string) $mime) . ';base64,' . base64_encode((string) $data) . '"' . $matches[4] . '>';
        }, $html) ?? $html;
    }

    private function normalizeImageBox(string $html, string $class, string $width, string $height): string
    {
        $classes = preg_split('/\s+/', $class) ?: [];
        $lookaheads = implode('', array_map(static fn (string $item): string => '(?=[^"]*\b' . preg_quote($item, '/') . '\b)', $classes));
        $pattern = '/<div\b([^>]*\bclass="' . $lookaheads . '[^"]*\bhas-image\b[^"]*"[^>]*)>\s*<img([^>]*)>\s*<\/div>/i';
        return preg_replace_callback($pattern, static function (array $matches) use ($class, $width, $height): string {
            $attrs = preg_replace('/\s(?:width|height|style)="[^"]*"/', '', $matches[2]) ?? $matches[2];
            return '<div class="' . $class . ' has-image"><img' . $attrs . ' width="' . $width . '" height="' . $height . '" style="display:block;width:' . $width . ';height:' . $height . ';max-width:' . $width . ';max-height:' . $height . ';margin:0 auto;border:0;"></div>';
        }, $html) ?? $html;
    }

    private function dompdfCertificateCss(): string
    {
        return '
@page { size: A4 portrait; margin: 0; }
html, body { margin: 0 !important; padding: 0 !important; }
.certificate { margin: 0 !important; padding: 0 !important; width: 210mm !important; height: 297mm !important; overflow: hidden !important; background: #ededed !important; }
.certificate-page { position: relative !important; width: 210mm !important; height: 297mm !important; margin: 0 !important; padding: 0 !important; overflow: hidden !important; background: #ededed !important; box-sizing: border-box !important; page-break-after: avoid !important; }
.certificate-card { position: absolute !important; left: 20mm !important; top: 20mm !important; width: 144mm !important; height: 240mm !important; margin: 0 !important; padding: 10mm 13mm 7mm !important; overflow: hidden !important; background: #fff !important; border-radius: 5mm !important; box-sizing: content-box !important; }
.certificate-title { margin: 0 0 7mm !important; font-size: 24px !important; line-height: 1.16 !important; }
.certificate-copy { width: 142mm !important; font-size: 11px !important; line-height: 1.34 !important; }
.certificate-copy p { margin: 0 0 5mm !important; }
.thanks-line { margin: 0 0 3mm !important; font-size: 11px !important; line-height: 1.3 !important; }
.recipient { width: 108mm !important; margin: 0 auto 5mm !important; padding-bottom: 1mm !important; font-size: 21px !important; line-height: 1.1 !important; }
.ownership { width: 142mm !important; margin: 0 auto 5mm !important; font-size: 11px !important; line-height: 1.3 !important; }
.signature-section { margin-top: 1mm !important; font-size: 11px !important; line-height: 1.3 !important; }
.signature-section p { margin: 0 0 2.5mm !important; }
.logo-placeholder { width: 42mm !important; height: 22mm !important; margin: 0 auto 7mm !important; padding-top: 6mm !important; box-sizing: border-box !important; }
.logo-placeholder.has-image { padding: 0 !important; background: transparent !important; border: 0 !important; line-height: 0 !important; font-size: 0 !important; }
.signature-placeholder { width: 58mm !important; height: 16mm !important; margin: 1mm auto 0 !important; padding-top: 4mm !important; box-sizing: border-box !important; overflow: visible !important; }
.signature-placeholder.has-image { padding: 0 !important; }
.signature-line { width: 58mm !important; }
.quality-marks { position: absolute !important; right: 8mm !important; bottom: 6mm !important; width: 58mm !important; height: 16mm !important; overflow: visible !important; }
.quality-marks-table { position: absolute !important; right: 8mm !important; bottom: 6mm !important; width: 58mm !important; height: 16mm !important; border-collapse: collapse !important; border: 0 !important; }
.quality-marks-table td { border: 0 !important; padding: 0 !important; margin: 0 !important; vertical-align: bottom !important; }
.quality-mark-spacer { width: 6mm !important; }
.mark-placeholder.anbi { display: block !important; position: absolute !important; left: 0 !important; right: auto !important; bottom: 0 !important; width: 18mm !important; height: 15mm !important; padding-top: 4mm !important; box-sizing: border-box !important; overflow: hidden !important; }
.mark-placeholder.cbf { display: block !important; position: absolute !important; left: 24mm !important; right: auto !important; bottom: 0 !important; width: 34mm !important; height: 15mm !important; padding-top: 4mm !important; box-sizing: border-box !important; overflow: hidden !important; }
.mark-placeholder.has-image { padding: 0 !important; background: transparent !important; border: 0 !important; line-height: 0 !important; font-size: 0 !important; }
.signature-placeholder.has-image img { display: block !important; width: 58mm !important; height: auto !important; max-width: 58mm !important; max-height: 16mm !important; margin: 0 auto !important; }
.logo-placeholder.has-image img { display: block !important; width: 42mm !important; height: auto !important; max-width: 42mm !important; max-height: 22mm !important; margin: 0 auto !important; }
.mark-placeholder.anbi.has-image img { display: block !important; width: 18mm !important; height: auto !important; max-width: 18mm !important; max-height: 15mm !important; margin: 0 auto !important; }
.mark-placeholder.cbf.has-image img { display: block !important; width: 34mm !important; height: auto !important; max-width: 34mm !important; max-height: 15mm !important; margin: 0 auto !important; }
';
    }
}
