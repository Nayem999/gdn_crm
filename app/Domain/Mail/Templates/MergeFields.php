<?php

namespace App\Domain\Mail\Templates;

use App\Domain\Accounts\Models\Account;
use App\Domain\Company\Models\Company;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Models\Lead;
use App\Domain\Sales\Models\Quote;
use App\Domain\Settings\DisplayTime;
use App\Domain\Settings\NumberFormat;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * What {{tokens}} an email template may use, and what they resolve to.
 *
 * Declared explicitly rather than reflected off the model. Two reasons, and the
 * second is the important one: the editor needs a label for each field to offer
 * a picker, and — more to the point — a template is written by an administrator
 * and read by a customer, so which columns are exposed should be a decision
 * somebody made rather than whatever happens to be on the table. Reflection
 * would have put `password_reset_token` in a merge picker the day somebody
 * added one.
 *
 * Values go through TemplateRenderer, which substitutes text and never compiles
 * anything, so neither an administrator nor a record's contents can turn a
 * message into code.
 */
final class MergeFields
{
    /**
     * The modules a template can be written against.
     *
     * @return array<string, string>
     */
    public static function modules(): array
    {
        return [
            'contact' => 'Contact',
            'lead' => 'Lead',
            'deal' => 'Deal',
            'quote' => 'Quote',
            'account' => 'Account',
        ];
    }

    public static function modelFor(string $module): ?string
    {
        return match ($module) {
            'contact' => Contact::class,
            'lead' => Lead::class,
            'deal' => Deal::class,
            'quote' => Quote::class,
            'account' => Account::class,
            default => null,
        };
    }

    /**
     * Every token a template for this module may use, token => label.
     *
     * The sender and the company are on every list because they are on every
     * message, whatever it is about.
     *
     * @return array<string, string>
     */
    public static function for(string $module): array
    {
        return [
            ...self::subjectFields($module),
            'sender.name' => 'Sender: name',
            'sender.email' => 'Sender: email',
            'company.name' => 'Company: name',
            'company.address' => 'Company: address',
            'company.city' => 'Company: city',
            'company.country' => 'Company: country',
            'today' => "Today's date",
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function subjectFields(string $module): array
    {
        $fields = match ($module) {
            'contact' => [
                'first_name' => 'First name', 'last_name' => 'Last name', 'full_name' => 'Full name',
                'email' => 'Email', 'phone' => 'Phone', 'mobile' => 'Mobile', 'job_title' => 'Job title',
                'account_name' => 'Account name',
            ],
            'lead' => [
                'first_name' => 'First name', 'last_name' => 'Last name', 'full_name' => 'Full name',
                'email' => 'Email', 'phone' => 'Phone', 'company_name' => 'Company', 'job_title' => 'Job title',
            ],
            'deal' => ['name' => 'Name', 'amount' => 'Value', 'close_date' => 'Expected close date', 'account_name' => 'Account name'],
            'quote' => [
                'number' => 'Number', 'total' => 'Total', 'valid_until' => 'Valid until',
                'bill_to_name' => 'Billed to', 'bill_to_email' => 'Billing email',
            ],
            'account' => ['name' => 'Name', 'website' => 'Website', 'phone' => 'Phone', 'city' => 'City', 'country' => 'Country'],
            default => [],
        };

        $prefixed = [];
        $label = self::modules()[$module] ?? ucfirst($module);

        foreach ($fields as $key => $fieldLabel) {
            $prefixed[$module.'.'.$key] = $label.': '.$fieldLabel;
        }

        return $prefixed;
    }

    /**
     * The values behind those tokens for one record.
     *
     * @return array<string, mixed>
     */
    public static function data(string $module, ?Model $subject, ?User $sender = null): array
    {
        $company = Company::current();

        return [
            $module => $subject === null ? [] : self::subjectData($module, $subject),
            'sender' => [
                'name' => $sender === null ? '' : $sender->name,
                'email' => $sender === null ? '' : $sender->email,
            ],
            'company' => [
                'name' => $company->name,
                'address' => trim(implode(', ', array_filter([
                    $company->address_line_1, $company->address_line_2, $company->city, $company->postal_code,
                ]))),
                'city' => (string) $company->city,
                'country' => (string) $company->country,
            ],
            'today' => DisplayTime::date(now()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function subjectData(string $module, Model $subject): array
    {
        // Matched on the concrete class as well as the module key, so a
        // template written for a quote cannot be rendered against a contact
        // that happens to be passed in — and so the property reads below are
        // reads of properties that exist.
        return match (true) {
            $module === 'contact' && $subject instanceof Contact => [
                'first_name' => $subject->first_name,
                'last_name' => $subject->last_name,
                'full_name' => trim($subject->first_name.' '.$subject->last_name),
                'email' => $subject->email,
                'phone' => $subject->phone,
                'mobile' => $subject->mobile,
                'job_title' => $subject->job_title,
                'account_name' => $subject->account?->name,
            ],
            $module === 'lead' && $subject instanceof Lead => [
                'first_name' => $subject->first_name,
                'last_name' => $subject->last_name,
                'full_name' => trim($subject->first_name.' '.$subject->last_name),
                'email' => $subject->email,
                'phone' => $subject->phone,
                'company_name' => $subject->company_name,
                'job_title' => $subject->job_title,
            ],
            $module === 'deal' && $subject instanceof Deal => [
                'name' => $subject->name,
                // Formatted here rather than left as a Carbon or a decimal
                // string: a customer reads these, and "2026-11-30 00:00:00" in
                // the middle of a sentence is how a template looks broken.
                'amount' => NumberFormat::format((float) $subject->getAttributeValue('value')),
                'close_date' => $subject->expected_close_date === null ? '' : DisplayTime::date($subject->expected_close_date),
                'account_name' => $subject->account?->name,
            ],
            $module === 'quote' && $subject instanceof Quote => [
                'number' => $subject->number,
                'total' => NumberFormat::format((float) $subject->getAttributeValue('total')),
                'valid_until' => $subject->valid_until === null ? '' : DisplayTime::date($subject->valid_until),
                'bill_to_name' => $subject->bill_to_name,
                'bill_to_email' => $subject->bill_to_email,
            ],
            $module === 'account' && $subject instanceof Account => [
                'name' => $subject->name,
                'website' => $subject->website,
                'phone' => $subject->phone,
                'city' => $subject->city,
                'country' => $subject->country,
            ],
            default => [],
        };
    }
}
