<?php

namespace App\Domain\Meta\Leads;

use App\Domain\Ingestion\Models\DataSourceMapping;
use Illuminate\Support\Collection;

/**
 * What a Meta lead form maps to before anybody configures anything.
 *
 * Meta's own forms are built from a catalogue of standard questions, and their
 * keys are fixed: a form asking for an email address sends `email`, whatever
 * the customer saw written above the box. So an installation that connects a
 * page and does nothing else should still get leads — asking somebody to hand-
 * map `email` to `email` before their first lead works is asking them to
 * discover the mapping screen by losing a lead.
 *
 * These are used **only when the source has no mappings of its own**. The
 * moment an administrator saves one, theirs is the whole mapping: a default
 * quietly merged underneath would write columns they had deliberately left
 * empty, and there would be no screen on which that rule was visible.
 *
 * The order matters. A row whose value is absent writes nothing, so the
 * general case goes first and the specific one after it: `full_name` fills the
 * name for a form that only asks for one, and a form that asks for both parts
 * overwrites it with the parts.
 */
final class MetaLeadDefaults
{
    /**
     * Meta's standard question keys, mapped to fields the lead module declares.
     *
     * @return Collection<int, DataSourceMapping>
     */
    public static function mappings(): Collection
    {
        $rows = [
            // A single name box, split on the last whitespace group — right for
            // "Maria del Carmen Okafor", where taking the second word is not.
            ['field_data.full_name', 'first_name', 'name_first'],
            ['field_data.full_name', 'last_name', 'name_last'],
            ['field_data.first_name', 'first_name', 'trim'],
            ['field_data.last_name', 'last_name', 'trim'],

            // A business form usually asks for the work address; a consumer
            // form asks for "email". Both land in the one column we keep, with
            // the more specific question winning where a form asks for both.
            ['field_data.email', 'email', 'trim'],
            ['field_data.work_email', 'email', 'trim'],

            ['field_data.phone_number', 'phone', 'trim'],
            ['field_data.work_phone_number', 'phone', 'trim'],

            ['field_data.company_name', 'company_name', 'trim'],
            ['field_data.job_title', 'job_title', 'trim'],

            ['field_data.street_address', 'address_line_1', 'trim'],
            ['field_data.city', 'city', 'trim'],
            // Meta asks either, depending on the country the form targets.
            ['field_data.state', 'state', 'trim'],
            ['field_data.province', 'state', 'trim'],
            ['field_data.post_code', 'postal_code', 'trim'],
            ['field_data.zip_code', 'postal_code', 'trim'],
            ['field_data.country', 'country', 'trim'],
        ];

        $mappings = [];
        $position = 0;

        foreach ($rows as [$path, $field, $transform]) {
            $mapping = new DataSourceMapping;

            // Never saved. These are handed to the mapper as the source's
            // mappings for one delivery, so a default cannot silently become a
            // row somebody later finds on the mapping screen and cannot explain.
            $mapping->forceFill([
                'source_path' => $path,
                'target_field' => $field,
                'is_custom_field' => false,
                'transform' => $transform,
                'transform_options' => null,
                'default_value' => null,
                // Requiredness is not a mapping's business here: the module's
                // own required fields are checked against the finished row, so
                // a form that asks no name fails for the honest reason rather
                // than "a default mapping said so".
                'is_required' => false,
                'position' => $position++,
            ]);

            $mappings[] = $mapping;
        }

        return new Collection($mappings);
    }

    /**
     * The question keys these cover, for a screen that wants to say which of a
     * form's questions will map themselves and which need a rule.
     *
     * @return array<int, string>
     */
    public static function coveredQuestions(): array
    {
        $covered = [];

        foreach (self::mappings() as $mapping) {
            $covered[] = str_replace('field_data.', '', $mapping->source_path);
        }

        return array_values(array_unique($covered));
    }
}
