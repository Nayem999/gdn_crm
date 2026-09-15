<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Facebook actually sent, kept beside the lead it became.
 *
 * Two tables rather than columns on `leads`, for two different reasons.
 *
 * `meta_leads` is the record of a submission, and it has to exist even when no
 * lead does: a delivery whose form asks nothing we can map, or which arrives
 * for a page this installation no longer manages, is still a thing that
 * happened at Meta's end and still has to be answerable when somebody asks why
 * a lead they can see in Ads Manager is not here. `meta_lead_id` is unique
 * because it is the idempotency key for the whole feature — Meta retries, and
 * one submission must be one lead however many times it is delivered.
 *
 * `meta_forms` is a cache of somebody else's configuration, kept so that
 * attribution can say "Spring offer enquiry" rather than "form 5566778899",
 * and so the backfill has something to walk. It is upserted and never deleted:
 * a form archived at Meta is still the form last quarter's leads came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_forms', function (Blueprint $table) {
            $table->id();

            // Meta's ids are beyond 32 bits and arrive as strings. They are
            // never arithmetic, so they are stored as what they are.
            $table->string('form_id', 64)->unique();

            // The page's Meta id rather than a foreign key into meta_pages: a
            // lead can arrive for a page whose row has not been synced yet, and
            // a constraint would make that delivery fail rather than be
            // recorded. The relation is resolved through this when there is one.
            $table->string('page_id', 64)->nullable()->index();

            $table->string('name');
            $table->string('status', 40)->nullable();

            // The questions as Meta defines them, so the mapping screen can
            // offer them and somebody can see what changed when a form is
            // edited at Meta's end and leads stop mapping.
            $table->json('questions')->nullable();

            $table->dateTime('last_lead_at')->nullable();
            $table->dateTime('last_synced_at')->nullable();

            $table->timestamps();
        });

        Schema::create('meta_leads', function (Blueprint $table) {
            $table->id();

            // The whole feature's idempotency key. Unique at the database
            // rather than checked in PHP: two queue workers can both pass a
            // check-then-insert, and the failure that produces is a duplicate
            // lead nobody notices until a salesperson rings twice.
            $table->string('meta_lead_id', 64)->unique();

            $table->foreignId('meta_form_id')->nullable()->constrained('meta_forms')->nullOnDelete();

            // Meta's own ids, kept flat as well as through the relation: the
            // form row can be missing when a lead arrives, and these are what
            // the figures in 12.13 group by.
            $table->string('form_id', 64)->nullable()->index();
            $table->string('page_id', 64)->nullable()->index();

            // Prefixed `meta_` throughout, the way marketing_attributions is:
            // `campaign_id` in this application means the CRM campaign, and a
            // column that meant Meta's here and ours everywhere else is a join
            // somebody eventually writes backwards.
            $table->string('meta_campaign_id', 64)->nullable()->index();
            $table->string('meta_campaign_name')->nullable();
            $table->string('meta_ad_set_id', 64)->nullable();
            $table->string('meta_ad_set_name')->nullable();
            $table->string('meta_ad_id', 64)->nullable()->index();
            $table->string('meta_ad_name')->nullable();

            // What Graph answered, as it answered it. longText rather than json
            // for the same reason integration_events.payload is: it is somebody
            // else's document, stored rather than reserialised, and it is data
            // — never evaluated, never unserialised, rendered escaped.
            $table->longText('payload')->nullable();

            // nullOnDelete rather than cascade: deleting a lead must not erase
            // the evidence of where it came from, or a deleted duplicate takes
            // the account of Meta's delivery with it.
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();

            $table->string('status', 16)->default('received');
            $table->text('error')->nullable();

            // dateTime, not timestamp: MySQL hands the first NOT NULL TIMESTAMP
            // column an implicit ON UPDATE CURRENT_TIMESTAMP, and processing a
            // lead would silently rewrite when it arrived.
            $table->dateTime('received_at');
            $table->dateTime('processed_at')->nullable();

            $table->timestamps();

            // "What is failing, most recent first" is the question this table
            // gets asked.
            $table->index(['status', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_leads');
        Schema::dropIfExists('meta_forms');
    }
};
