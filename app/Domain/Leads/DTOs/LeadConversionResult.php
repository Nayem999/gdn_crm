<?php

namespace App\Domain\Leads\DTOs;

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Models\Lead;

/**
 * What a lead turned into.
 *
 * Returned whether the conversion just ran or had already happened, so a
 * caller never has to ask which — that is what makes the action idempotent
 * from the outside as well as the inside.
 */
readonly class LeadConversionResult
{
    public function __construct(
        public Lead $lead,
        public Account $account,
        public Contact $contact,
        public ?Deal $deal,
        /** False when the lead was already converted and this is the earlier result. */
        public bool $justConverted,
    ) {}
}
