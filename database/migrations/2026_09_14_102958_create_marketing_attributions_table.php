<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a record came from, in the advertiser's own terms.
 *
 * One table rather than sixteen columns on each of leads, contacts and deals.
 * The brief lists the fields per module, and three copies of them would be
 * forty-eight mostly-null columns on the three busiest tables in the
 * application — plus three places to write them, three to read them, and three
 * chances for conversion to forget one.
 *
 * Every field is a real column, not JSON: attribution is exactly the thing that
 * has to be filtered, grouped and joined, which is what a JSON blob cannot do.
 *
 * The CRM-level link stays on the record itself — `leads.campaign_id` and its
 * siblings, added in 12.2 — because that is what the list screens filter by and
 * what a person edits. This table is the detail behind it: which ad, which
 * form, which click.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_attributions', function (Blueprint $table) {
            $table->id();

            // One row per record. The unique index is what makes that true
            // rather than hoped for — a second row would be a second answer to
            // "where did this come from?".
            $table->string('attributable_type');
            $table->unsignedBigInteger('attributable_id');

            // What the record's source was at capture. Kept beside the record's
            // own `source` column on purpose: somebody re-categorising a lead
            // by hand should not rewrite history, and "what did we think when
            // it arrived?" is the question a marketing report asks.
            $table->string('source', 60)->nullable();
            $table->string('source_detail')->nullable();

            // Meta's own identifiers. Strings, not integers: Meta's ids are
            // beyond 32 bits, arrive as strings, and are never arithmetic.
            $table->string('meta_lead_id', 64)->nullable();
            $table->string('page_id', 64)->nullable();
            $table->string('form_id', 64)->nullable();
            $table->string('form_name')->nullable();

            $table->string('meta_campaign_id', 64)->nullable();
            $table->string('meta_campaign_name')->nullable();
            $table->string('meta_ad_set_id', 64)->nullable();
            $table->string('meta_ad_set_name')->nullable();
            $table->string('meta_ad_id', 64)->nullable();
            $table->string('meta_ad_name')->nullable();

            // fbclid, or the click-to-WhatsApp equivalent. What the Conversions
            // API matches an outcome back to an impression with.
            $table->string('click_id', 255)->nullable();

            $table->string('utm_source', 191)->nullable();
            $table->string('utm_medium', 191)->nullable();
            $table->string('utm_campaign', 191)->nullable();
            $table->string('utm_content', 191)->nullable();
            $table->string('utm_term', 191)->nullable();

            // When the attribution was first recorded, which is not the same as
            // when the row was written: a lead that arrives through a queue is
            // attributed to the moment the customer acted.
            //
            // dateTime rather than timestamp: MySQL gives the first NOT NULL
            // TIMESTAMP column an implicit ON UPDATE CURRENT_TIMESTAMP.
            $table->dateTime('captured_at');

            $table->timestamps();

            $table->unique(['attributable_type', 'attributable_id']);

            // Every field a report groups by or a sync looks up.
            $table->index('meta_lead_id');
            $table->index('meta_campaign_id');
            $table->index('meta_ad_set_id');
            $table->index('meta_ad_id');
            $table->index('form_id');
            $table->index('source');
            $table->index('utm_campaign');
            $table->index('captured_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_attributions');
    }
};
