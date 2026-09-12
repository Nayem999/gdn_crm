<?php

namespace App\Http\Controllers;

use App\Domain\Sales\Documents\QuotePdf;
use App\Domain\Sales\Models\Quote;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * The quote as the customer receives it.
 *
 * Behind the quote's own policy, so a PDF is never a way round the access level
 * that governs the record — the document holds prices, margins are inferable
 * from them, and "it is only a PDF" is how that gets forgotten.
 */
class DownloadQuotePdf extends Controller
{
    use AuthorizesRequests;

    public function __invoke(Quote $quote, QuotePdf $pdf): Response
    {
        $this->authorize('view', $quote);

        return response($pdf->render($quote), 200, [
            'Content-Type' => 'application/pdf',
            // Inline: somebody checking a quote wants to look at it, not to
            // find it in their downloads folder.
            'Content-Disposition' => 'inline; filename="'.$pdf->filename($quote).'"',
        ]);
    }
}
