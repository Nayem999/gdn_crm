<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\Capture\CaptureRejected;
use App\Domain\Leads\Capture\CaptureTimestamp;
use App\Domain\Leads\DTOs\LeadData;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadCaptureForm;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Turns a public submission into a lead.
 *
 * The only unauthenticated write path in the application, so the shape of this
 * class is the shape of that risk:
 *
 * - **Nothing from the payload names a column.** Only keys the form declares,
 *   and only ones in `CaptureField`'s fixed catalogue, reach `LeadData`.
 * - **The owner and the source come from the form**, never the request. A
 *   submission that could choose its owner could route itself to somebody who
 *   would not look at it.
 * - **Two spam signals, both quiet.** A filled honeypot and a form submitted
 *   faster than a person can read it are both rejected, and the visitor is
 *   shown the same success page either way — telling a bot which signal caught
 *   it is telling whoever wrote it what to change. Rate limiting is the third,
 *   and lives on the route where it can answer before any of this runs.
 */
class SubmitLeadCaptureAction
{
    public function __construct(private readonly CreateLeadAction $createLead) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws CaptureRejected when the submission is refused
     */
    public function __invoke(LeadCaptureForm $form, array $payload): Lead
    {
        if (! $form->is_active) {
            // Not silent: a person following a link from a real website
            // deserves to know the form is closed rather than to think their
            // enquiry landed.
            throw new CaptureRejected('This form is no longer accepting submissions.', silent: false);
        }

        $this->guardHoneypot($form, $payload);
        $this->guardSpeed($form, $payload);

        $fields = $form->captureFields();

        $rules = [];
        $labels = [];

        foreach ($fields as $field) {
            $rules[$field->key] = $field->validationRules();
            $labels[$field->key] = strtolower($field->label);
        }

        // A lead has to be reachable somehow, whatever the form chose to ask.
        $validator = Validator::make($payload, $rules, [], $labels);

        $validator->after(function ($validator) use ($payload) {
            if (trim((string) ($payload['email'] ?? '')) === '' && trim((string) ($payload['phone'] ?? '')) === '') {
                $validator->errors()->add('email', 'Give an email address or a phone number.');
            }
        });

        $validator->validate();

        $attributes = [];

        foreach ($fields as $field) {
            $value = $payload[$field->key] ?? null;
            $attributes[$field->key] = is_string($value) ? trim($value) : $value;
        }

        $lead = $this->createLead->__invoke(
            LeadData::fromArray([
                ...$attributes,
                // From the form, never the payload.
                'source' => $form->source()->value,
                // The form names one person; that person becomes the new
                // lead's sole assignee, unprioritised. An admin adds more
                // from the lead itself once it exists.
                'assignees' => [['user_id' => $form->owner_id, 'priority' => null]],
            ]),
            $form->owner,
        );

        $form->forceFill([
            'submission_count' => $form->submission_count + 1,
            'last_submitted_at' => now(),
        ])->save();

        return $lead;
    }

    /**
     * A field no person sees and every naive bot fills in.
     *
     * @param  array<string, mixed>  $payload
     */
    private function guardHoneypot(LeadCaptureForm $form, array $payload): void
    {
        if (trim((string) ($payload[LeadCaptureForm::HONEYPOT] ?? '')) === '') {
            return;
        }

        Log::info('Lead capture rejected: honeypot', ['form' => $form->id]);

        throw new CaptureRejected('honeypot');
    }

    /**
     * A form submitted faster than a person could read it.
     *
     * The timestamp is **signed**, so a bot cannot simply post an older one —
     * and an unsigned or missing value is treated as a rejection rather than
     * waved through, which is the direction that fails safe.
     *
     * @param  array<string, mixed>  $payload
     */
    private function guardSpeed(LeadCaptureForm $form, array $payload): void
    {
        $renderedAt = CaptureTimestamp::read((string) ($payload[LeadCaptureForm::TIMESTAMP] ?? ''));

        if ($renderedAt === null) {
            Log::info('Lead capture rejected: unsigned timestamp', ['form' => $form->id]);

            throw new CaptureRejected('timestamp missing or tampered with');
        }

        if ($renderedAt->diffInSeconds(now(), true) < LeadCaptureForm::MINIMUM_SECONDS) {
            Log::info('Lead capture rejected: too fast', ['form' => $form->id]);

            throw new CaptureRejected('submitted too quickly');
        }
    }
}
