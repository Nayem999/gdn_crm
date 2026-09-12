<?php

namespace App\Domain\Mail\Actions;

use App\Domain\Mail\Models\EmailTemplate;
use App\Domain\Mail\Templates\MergeFields;
use App\Domain\Mail\Templates\RenderedEmail;
use App\Domain\Mail\Tracking\EmailTracking;
use App\Domain\Notifications\TemplateRenderer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Fill in a template for one record.
 *
 * The subject is never tracked and never rewritten — only the body is. And the
 * tracking id is minted here rather than at send time, because the body cannot
 * be written without it.
 */
class RenderEmailTemplateAction
{
    public function __construct(private readonly TemplateRenderer $renderer) {}

    public function handle(EmailTemplate $template, ?Model $subject = null, ?User $sender = null): RenderedEmail
    {
        $module = $template->getAttributeValue('module');
        $data = MergeFields::data($module, $subject, $sender);

        $subjectLine = $this->renderer->render($template->getAttributeValue('subject'), $data);
        $body = $this->renderer->render($template->getAttributeValue('body'), $data);

        if (! $template->track_opens && ! $template->track_clicks) {
            return new RenderedEmail($subjectLine, $body);
        }

        $trackingId = (string) Str::uuid();

        // Links first: adding the pixel first would put an image tag in front
        // of the link rewriter, which has nothing to do with href attributes —
        // but the order also means the pixel's own URL is never a candidate for
        // rewriting, which is the part that would actually break.
        if ($template->track_clicks) {
            $body = EmailTracking::withTrackedLinks($body, $trackingId);
        }

        if ($template->track_opens) {
            $body = EmailTracking::withPixel($body, $trackingId);
        }

        return new RenderedEmail($subjectLine, $body, $trackingId);
    }
}
