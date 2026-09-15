<?php

namespace App\Http\Controllers;

use App\Domain\Social\Models\SocialMessage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a WhatsApp attachment to somebody allowed to see the conversation.
 *
 * The only door to these bytes. They sit on the private disk, nothing about them
 * is reachable by URL except through here, and the **conversation's** policy is
 * asked before anything moves — an attachment is exactly as visible as the
 * thread it arrived in, which is the same rule documents follow through their
 * record.
 *
 * Always an attachment, never inline. A customer can send anything, including an
 * HTML file or an SVG, and rendering one on this origin is how it would run
 * script here.
 */
class DownloadSocialMedia extends Controller
{
    use AuthorizesRequests;

    public function __invoke(SocialMessage $message): StreamedResponse
    {
        $conversation = $message->conversation;

        abort_if($conversation === null, 404);

        $this->authorize('view', $conversation);

        $file = $message->attachmentFile();

        // A message whose file was never fetched, or whose fetch failed, is a
        // gap rather than a server error.
        abort_if($file === null, 404);

        $response = $file->toResponse(request());

        // Through the header bag: a StreamedResponse has no
        // setContentDisposition(), and makeDisposition() escapes a filename that
        // is not plain ASCII. The stored name is minted by
        // FetchWhatsAppMediaAction, so it cannot be steered from outside.
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            'attachment',
            (string) $file->file_name,
        ));

        return $response;
    }
}
