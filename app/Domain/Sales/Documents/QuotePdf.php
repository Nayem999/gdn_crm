<?php

namespace App\Domain\Sales\Documents;

use App\Domain\Company\Models\Company;
use App\Domain\Sales\Models\Quote;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * A quote as a PDF.
 *
 * Rendered from the **stored line figures**, not recomputed: the PDF is what
 * the customer receives, and a document that recalculates itself at render time
 * could print something different from what the screen showed when somebody
 * pressed send.
 *
 * Branding comes from the company profile. The logo is embedded as a data URI
 * rather than a URL, because dompdf fetching an image over HTTP at render time
 * means a PDF whose appearance depends on the network — and on a queue worker
 * that may not be able to reach the site at all.
 */
class QuotePdf
{
    public function render(Quote $quote): string
    {
        return Pdf::loadView('pdf.quote', $this->data($quote))
            ->setPaper('a4')
            ->output();
    }

    /**
     * What to call the file. The reference, so a folder of them sorts and reads
     * sensibly: "Q-2026-0007-v2.pdf".
     */
    public function filename(Quote $quote): string
    {
        return str_replace(' ', '-', $quote->reference()).'.pdf';
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Quote $quote): array
    {
        $company = Company::current();

        return [
            'quote' => $quote->load(['lines', 'owner', 'account']),
            'company' => $company,
            'logo' => $this->logo($company),
            'totals' => $quote->totals(),
            'currency' => $company->currency,
        ];
    }

    /**
     * The logo as a data URI, or null when there is none.
     *
     * Read from disk rather than fetched over HTTP: a PDF produced by a queue
     * worker must not depend on that worker being able to reach the web server.
     */
    private function logo(Company $company): ?string
    {
        $media = $company->getFirstMedia('logo');

        if ($media === null) {
            return null;
        }

        $path = $media->getPath();

        if (! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false
            ? null
            : 'data:'.$media->mime_type.';base64,'.base64_encode($contents);
    }
}
