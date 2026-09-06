<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Accounts\Actions\CreateAccountAction;
use App\Domain\Accounts\DTOs\AccountData;
use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Actions\CreateContactAction;
use App\Domain\Contacts\DTOs\ContactData;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Enums\DealStage;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\DTOs\LeadConversionData;
use App\Domain\Leads\DTOs\LeadConversionResult;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns a lead into an account, a person at it, and optionally a deal.
 *
 * All of it in one transaction: a lead that produced an account but no contact
 * is worse than one that was never converted, because the half-finished state
 * looks finished. Anything that throws leaves the lead exactly as it was.
 *
 * Converting twice is not an error and does not create a second set of records
 * — the earlier result comes back instead. That matters because a conversion
 * can be retried from a browser that never saw the first response, and will
 * matter more when the Phase 8 gateway can trigger one.
 */
class ConvertLeadAction
{
    public function __construct(
        private readonly CreateAccountAction $createAccount,
        private readonly CreateContactAction $createContact,
        private readonly ChangeLeadStatusAction $changeStatus,
    ) {}

    /**
     * @throws RuntimeException when the lead cannot be converted, or a record it
     *                          was told to link to does not exist
     */
    public function __invoke(Lead $lead, LeadConversionData $data, User $actor): LeadConversionResult
    {
        $existing = $this->alreadyConverted($lead);

        if ($existing !== null) {
            return $existing;
        }

        $this->guard($lead);

        $ownerId = $data->ownerId ?? $lead->owner_id;

        $result = DB::transaction(function () use ($lead, $data, $actor, $ownerId) {
            $account = $this->account($lead, $data, $actor, $ownerId);
            $contact = $this->contact($lead, $data, $actor, $account, $ownerId);
            $deal = $data->createDeal ? $this->deal($lead, $data, $account, $contact, $ownerId) : null;

            $lead->forceFill([
                'converted_at' => now(),
                'converted_account_id' => $account->id,
                'converted_contact_id' => $contact->id,
                'converted_deal_id' => $deal?->id,
            ])->save();

            // force() rather than __invoke(): Converted is deliberately absent
            // from every transition list, so this is the one caller entitled to
            // set it, and only once the three records genuinely exist.
            $this->changeStatus->force($lead, LeadStatus::Converted);

            return new LeadConversionResult($lead->refresh(), $account, $contact, $deal, true);
        });

        return $result;
    }

    /**
     * The result of an earlier conversion, or null if there was not one.
     */
    public function alreadyConverted(Lead $lead): ?LeadConversionResult
    {
        if ($lead->converted_at === null) {
            return null;
        }

        $account = $lead->convertedAccount;
        $contact = $lead->convertedContact;

        // Both were removed after the fact, so there is nothing to hand back and
        // nothing sensible to re-create either.
        if ($account === null || $contact === null) {
            return null;
        }

        return new LeadConversionResult($lead, $account, $contact, $lead->convertedDeal, false);
    }

    /**
     * @throws RuntimeException
     */
    private function guard(Lead $lead): void
    {
        $status = $lead->status();

        if ($status === LeadStatus::Converted) {
            throw new RuntimeException('That lead has already been converted.');
        }

        if ($status === LeadStatus::Unqualified) {
            throw new RuntimeException('An unqualified lead cannot be converted. Move it back into play first.');
        }
    }

    private function account(Lead $lead, LeadConversionData $data, User $actor, ?int $ownerId): Account
    {
        if ($data->accountId !== null) {
            $account = Account::query()->find($data->accountId);

            if ($account === null) {
                throw new RuntimeException('That account does not exist.');
            }

            return $account;
        }

        $name = trim((string) ($data->accountName ?? $lead->company_name ?? ''));

        if ($name === '') {
            // A lead captured with no company still has to land somewhere, and
            // an account named after the person is better than a blank one.
            $name = $lead->fullName();
        }

        return ($this->createAccount)(new AccountData(
            name: $name,
            website: $lead->website,
            email: $lead->email,
            phone: $lead->phone,
            addressLine1: $lead->address_line_1,
            addressLine2: $lead->address_line_2,
            city: $lead->city,
            state: $lead->state,
            postalCode: $lead->postal_code,
            country: $lead->country,
            ownerId: $ownerId,
        ), $actor);
    }

    private function contact(Lead $lead, LeadConversionData $data, User $actor, Account $account, ?int $ownerId): Contact
    {
        if ($data->contactId !== null) {
            $contact = Contact::query()->find($data->contactId);

            if ($contact === null) {
                throw new RuntimeException('That contact does not exist.');
            }

            // Linking an existing person to the account they are being converted
            // into, so the deal has somebody to talk to.
            if ($contact->account_id === null) {
                $contact->forceFill(['account_id' => $account->id])->save();
            }

            return $contact->refresh();
        }

        return ($this->createContact)(new ContactData(
            firstName: $lead->first_name,
            lastName: $lead->last_name,
            jobTitle: $lead->job_title,
            email: $lead->email,
            phone: $lead->phone,
            mobile: $lead->mobile,
            addressLine1: $lead->address_line_1,
            addressLine2: $lead->address_line_2,
            city: $lead->city,
            state: $lead->state,
            postalCode: $lead->postal_code,
            country: $lead->country,
            accountId: $account->id,
            ownerId: $ownerId,
        ), $actor);
    }

    private function deal(Lead $lead, LeadConversionData $data, Account $account, Contact $contact, ?int $ownerId): Deal
    {
        $name = trim((string) ($data->dealName ?? ''));

        return Deal::create([
            'name' => $name !== '' ? $name : $account->name.' opportunity',
            'account_id' => $account->id,
            'contact_id' => $contact->id,
            'lead_id' => $lead->id,
            'value' => $data->dealValue ?? $lead->estimated_value,
            'expected_close_date' => $data->dealCloseDate,
            'stage' => DealStage::New->value,
            'owner_id' => $ownerId ?? $lead->owner_id,
        ]);
    }
}
