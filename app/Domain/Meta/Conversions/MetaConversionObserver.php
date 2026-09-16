<?php

namespace App\Domain\Meta\Conversions;

use App\Domain\Attribution\Models\RecordAttribution;
use App\Domain\Company\Models\Company;
use App\Domain\Deals\Enums\DealStage;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Meta\Conversions\Actions\QueueConversionAction;
use App\Domain\Meta\Conversions\Enums\ConversionOutcome;
use App\Domain\Meta\MetaConfiguration;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Noticing the three moments worth reporting, wherever they happen.
 *
 * An observer rather than a call inside `CloseDealAction`, because a deal is
 * won from at least four places — the pipeline board, the deal screen, an
 * import, a workflow — and a report that only fired from one of them would
 * understate exactly the campaigns that were working well enough for somebody
 * to close the deal quickly.
 *
 * **It reads the transition, not the state.** `wasChanged` is what makes this
 * safe to attach to `saved`: a deal already won and then renamed must not
 * report a second win, and an unconditional check on "is this won" would.
 *
 * Nothing here decides whether to send. That is
 * {@see QueueConversionAction}'s job, and keeping the decision there means one
 * place answers "should Meta hear about this" for every trigger that will be
 * added later.
 */
class MetaConversionObserver
{
    public function __construct(
        private readonly QueueConversionAction $queue,
        private readonly MetaConfiguration $config,
    ) {}

    public function saved(Model $record): void
    {
        // Asked first, and cheaply: this runs on every save of every lead, deal
        // and attribution row in the application, and an installation that
        // never configured the Conversions API must not pay a query on each one
        // to find that out. Settings are memoised per request.
        if ($this->config->datasetId() === null) {
            return;
        }

        match (true) {
            $record instanceof Lead => $this->lead($record),
            $record instanceof Deal => $this->deal($record),
            $record instanceof RecordAttribution => $this->attributionArrived($record),
            default => null,
        };
    }

    /**
     * A record has just learned where it came from.
     *
     * The trigger that makes the opportunity reportable at all. Lead conversion
     * creates the deal and copies the attribution across **inside the same
     * transaction, without saving the deal again** — so the deal's own observer
     * never sees a save with attribution present, and an opportunity that
     * waited for one would be reported whenever somebody next happened to edit
     * the deal, or never.
     */
    private function attributionArrived(RecordAttribution $attribution): void
    {
        $subject = $attribution->attributable;

        if (! $subject instanceof Deal) {
            return;
        }

        $this->report($subject, ConversionOutcome::Opportunity);

        // Attribution arriving on a deal that is already won is the backfill
        // case — a sale closed before anybody connected Meta. Both are worth
        // reporting, and both are refused if they have been already.
        if (DealStage::tryFrom((string) $subject->getAttributeValue('stage')) === DealStage::Won) {
            $this->report($subject, ConversionOutcome::Won);
        }
    }

    private function lead(Lead $lead): void
    {
        if (! $lead->wasChanged('status')) {
            return;
        }

        $status = LeadStatus::tryFrom((string) $lead->getAttributeValue('status'));

        if ($status !== LeadStatus::Qualified) {
            return;
        }

        ($this->queue)(
            $lead,
            ConversionOutcome::Qualified,
            $lead->attribution(),
            ['email' => $lead->email, 'phone' => $lead->mobile ?? $lead->phone],
            occurredAt: $this->moment($lead->updated_at),
        );
    }

    private function deal(Deal $deal): void
    {
        // The win first, so a deal created and won in one motion reports both
        // in the order they happened.
        if ($deal->wasChanged('stage') && DealStage::tryFrom((string) $deal->getAttributeValue('stage')) === DealStage::Won) {
            $this->report($deal, ConversionOutcome::Won);
        }

        // The opportunity is reported the first time a deal is seen carrying
        // Meta attribution — **not** when its row was inserted. Lead conversion
        // creates the deal and copies the attribution across afterwards, so a
        // check at creation finds nothing and would never look again; and
        // `wasRecentlyCreated` stays true for the rest of that instance's life,
        // so it cannot be used to mean "this save is the insert" either.
        //
        // Reporting it repeatedly is safe: the event id is deterministic, and
        // the queue action refuses one it has already recorded.
        $this->report($deal, ConversionOutcome::Opportunity);
    }

    private function report(Deal $deal, ConversionOutcome $outcome): void
    {
        $contact = $deal->contact;

        ($this->queue)(
            $deal,
            $outcome,
            // The deal's own, which carries the lead's: conversion copies
            // attribution forward, which is the only reason a won deal can be
            // traced to the advertisement that started it.
            $deal->attribution(),
            [
                'email' => $contact?->email,
                // Mobile first: it is the number a customer is reachable on,
                // and Meta matches whichever it is given.
                'phone' => $contact === null ? null : ($contact->mobile ?? $contact->phone),
            ],
            value: $outcome->carriesValue() ? (float) $deal->value : null,
            currency: $outcome->carriesValue() ? $this->currency() : null,
            // Meta reports an event *for* the moment the outcome happened
            // rather than the moment we noticed it.
            occurredAt: $this->moment($outcome->carriesValue() ? ($deal->closed_at ?? $deal->updated_at) : $deal->created_at),
        );
    }

    /**
     * The company's currency. Meta refuses a value without one, and guessing
     * USD would report a ৳400,000 win as $400,000.
     */
    private function currency(): ?string
    {
        $currency = Company::current()->currency;

        return $currency === '' ? null : $currency;
    }

    /**
     * Any Carbon, as the one this application passes about.
     *
     * Model timestamps arrive as the base Carbon while everything written by
     * hand here is Illuminate's; they behave identically and are not the same
     * type, so one conversion at the boundary beats a looser signature.
     */
    private function moment(?CarbonInterface $moment): ?Carbon
    {
        return $moment === null ? null : Carbon::instance($moment);
    }
}
