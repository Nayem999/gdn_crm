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

        // Always an attachment, never inline. A document is something somebody
        // saves and opens in its own application; rendering one in the browser
        // is how an uploaded page would run script on this origin, and the
        // allowlist in DocumentUploads should not be the only thing standing
        // between an upload and that.
        $response = $file->toResponse(request());

        // Through the header bag: a StreamedResponse has no
        // setContentDisposition(), and makeDisposition() is what escapes a
        // filename that is not plain ASCII. The stored name is already
        // sanitised by UploadDocumentAction, so this cannot be steered.
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            'attachment',
            (string) $file->file_name,
        ));

        return $response;
    }
}
