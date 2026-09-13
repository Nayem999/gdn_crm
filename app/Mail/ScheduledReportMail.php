<?php

namespace App\Mail;

use App\Domain\Reports\Documents\ReportDocument;
use App\Domain\Reports\Models\ReportSchedule;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A scheduled report, on its way out, with the file attached.
 *
 * The file is produced **when the mail is built**, inside the queued job, and
 * not carried through the queue as bytes — the same reasoning as QuoteMail: a
 * serialised attachment makes the job payload the size of the document, and a
 * morning's worth of scheduled reports would sit in Redis as megabytes of PDF.
 *
 * It is rendered as the schedule's owner, which is what keeps an attachment
 * from containing figures its recipients' colleague could not have seen.
 */
class ScheduledReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly ReportSchedule $schedule) {}

    public function envelope(): Envelope
    {
        $report = $this->schedule->report;

        return new Envelope(
            subject: $report === null ? 'Your scheduled report' : $report->name,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.scheduled-report', with: [
            'schedule' => $this->schedule,
            'report' => $this->schedule->report,
        ]);
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $report = $this->schedule->report;
        $owner = $this->schedule->owner;

        if ($report === null || $owner === null) {
            return [];
        }

        $document = app(ReportDocument::class);
        $format = $this->schedule->format();

        return [
            Attachment::fromData(
                fn (): string => $document->render($report, $owner, $format),
                $document->filename($report, $format),
            )->withMime($format->mimeType()),
        ];
    }
}
