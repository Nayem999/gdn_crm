<?php

namespace App\Http\Controllers;

use App\Domain\Timeline\Models\Document;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a document's file to somebody allowed to have it.
 *
 * A controller rather than a Livewire action because a download is a plain
 * response, and because this is the only door to the private disk: nothing
 * about a document is reachable by URL except through here, and DocumentPolicy
 * answers before a byte moves.
 */
class DownloadDocument extends Controller
{
    use AuthorizesRequests;

    public function __invoke(Document $document): StreamedResponse
    {
        $this->authorize('view', $document);

        $file = $document->mediaFile();

        // A row whose file went missing is a broken entry, not a server error.
        abort_if($file === null, 404);

        return $file->toResponse(request());
    }
}
