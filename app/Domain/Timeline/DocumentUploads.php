<?php

namespace App\Domain\Timeline;

/**
 * What a document may be.
 *
 * An allowlist, never a denylist: a denylist is a list of the things somebody
 * thought of, and the interesting upload is always the one they did not.
 *
 * What is deliberately absent, and why:
 *
 *   - **HTML and SVG.** Both execute script when a browser renders them. Even
 *     on a private disk served through an authorising controller, one careless
 *     inline disposition turns an upload into stored XSS on this application's
 *     own origin.
 *   - **Anything executable** — php, phtml, exe, sh, bat, jar. A CRM has no
 *     business storing them, and a web root that ever gains a symlink to this
 *     directory should not be one command away from remote code execution.
 *   - **Archives.** A zip is a way to smuggle the above past a mime check, and
 *     nothing in this application ever opens one.
 */
final class DocumentUploads
{
    /**
     * The largest document, in kilobytes.
     *
     * Below Livewire's own temporary-upload cap (12MB, config/livewire.php) or
     * the rejection comes from the framework, talking about a temporary file,
     * rather than from the screen talking about a document.
     */
    public const MAX_KILOBYTES = 10240;

    /**
     * The extensions a document may have.
     *
     * @return array<int, string>
     */
    public static function extensions(): array
    {
        return [
            // What a customer actually sends: signed paperwork, a scan, a
            // spreadsheet of figures, a photograph of a broken part.
            'pdf',
            'doc', 'docx', 'odt', 'rtf',
            'xls', 'xlsx', 'ods', 'csv',
            'ppt', 'pptx', 'odp',
            'txt', 'md',
            'png', 'jpg', 'jpeg', 'gif', 'webp', 'heic', 'bmp', 'tif', 'tiff',
        ];
    }

    /**
     * The validation rules for an uploaded document.
     *
     * `mimes` checks the file's real type rather than the name it arrived
     * with, which is what makes this worth more than an extension check.
     *
     * @return array<int, string>
     */
    public static function rules(): array
    {
        return [
            'required',
            'file',
            'max:'.self::MAX_KILOBYTES,
            'mimes:'.implode(',', self::extensions()),
        ];
    }

    /**
     * A sentence for the screen, so somebody refused knows what would work.
     */
    public static function hint(): string
    {
        return 'Documents, spreadsheets and images, up to '
            .(int) (self::MAX_KILOBYTES / 1024).'MB. Web pages and programs are not accepted.';
    }
}
