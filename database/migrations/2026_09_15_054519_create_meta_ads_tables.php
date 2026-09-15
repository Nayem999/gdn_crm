<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the money was spent on, and what it bought.
 *
 * **Every Meta object is keyed by Meta's own id, and these tables join on those
 * strings rather than on foreign keys of ours.** That is deliberate, and it is
 * the same choice `meta_forms` made: Meta paginates, an ad set routinely arrives
 * before the campaign it belongs to, and a sync that had to resolve an integer
 * parent would either fail on the ordering or write rows in a second pass. It
 * also means `meta_leads.meta_campaign_id` and
 * `marketing_attributions.meta_campaign_id` — which already hold Meta's ids —
 * join straight onto `meta_campaigns.meta_campaign_id` without a lookup table
 * in between, which is the whole of 12.13's attribution chain.
 *
 * So, throughout this phase: a column named `meta_*_id` is **Meta's** id and a
 * string; `campaign_id` is **ours** and an integer, the CRM campaign every
 * channel's spend is eventually compared in.
 *
 * Nothing here is ever deleted by a sync. A campaign that has gone from Meta's
 * answer — because permissions changed, or because Meta was having a bad minute
 * — keeps its row and its history: deleting it would take last quarter's
 * attribution with it, and a transient blip would silently cost a month of
 * marketing figures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_campaigns', function (Blueprint $table) {
            $table->id();

            $table->string('meta_campaign_id', 64)->unique();
            // Meta's ad account id, bare and without the `act_` prefix Meta's
            // own endpoints want — see MetaAdAccount::graphId().
            $table->string('ad_account_id', 64)->index();

            $table->string('name');
            $table->string('objective', 60)->nullable();
            // Two statuses, because Meta has two and they disagree in the way
            // that matters: `status` is what somebody set, `effective_status` is
            // what is actually happening — a campaign set ACTIVE inside an ad
            // account that has been disabled spends nothing, and only the
            // second says so.
            $table->string('status', 40)->nullable();
            $table->string('effective_status', 40)->nullable();

            $table->decimal('daily_budget', 15, 2)->nullable();
            $table->decimal('lifetime_budget', 15, 2)->nullable();

            $table->dateTime('start_time')->nullable();
            $table->dateTime('stop_time')->nullable();

            // The CRM campaign this is part of. **Unique**, which is what makes
            // §11's linking one-to-one rather than a quiet many-to-one: two Meta
            // campaigns pointed at one CRM campaign would double its spend in
            // every figure derived from it, and nothing on the screen would say
            // why. nullOnDelete, so removing a CRM campaign unlinks rather than
            // erasing what Meta told us.
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->unique('campaign_id');
            $table->dateTime('linked_at')->nullable();
            $table->foreignId('linked_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('last_synced_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('name');
        });

        Schema::create('meta_ad_sets', function (Blueprint $table) {
            $table->id();

            $table->string('meta_ad_set_id', 64)->unique();
            // Meta's campaign id, not ours. Indexed because every figure in
            // 12.13 rolls up through it.
            $table->string('meta_campaign_id', 64)->index();

            $table->string('name');
            $table->string('status', 40)->nullable();
            $table->string('effective_status', 40)->nullable();
            // What Meta was told to optimise for — LEAD_GENERATION, LINK_CLICKS
            // — which is what makes two ad sets' cost per lead comparable or
            // not.
            $table->string('optimisation_goal', 60)->nullable();
            $table->string('billing_event', 60)->nullable();

            $table->decimal('daily_budget', 15, 2)->nullable();
            $table->decimal('lifetime_budget', 15, 2)->nullable();

            $table->dateTime('start_time')->nullable();
            $table->dateTime('end_time')->nullable();

            $table->dateTime('last_synced_at')->nullable();

            $table->timestamps();

            $table->index('status');
        });

        Schema::create('meta_ads', function (Blueprint $table) {
            $table->id();

            $table->string('meta_ad_id', 64)->unique();
            $table->string('meta_ad_set_id', 64)->index();
            // Carried as well as the ad set's, so "which campaign did this lead
            // come from" is one join rather than two. Meta returns it on the ad,
            // so this is copying what was given rather than deriving it.
            $table->string('meta_campaign_id', 64)->nullable()->index();

            $table->string('name');
            $table->string('status', 40)->nullable();
            $table->string('effective_status', 40)->nullable();

            // Enough of the creative to recognise the advertisement in a list:
            // its title and body, not the asset. Storing the images would make
            // this table a media library nobody asked for, and Meta's own URLs
            // expire.
            $table->string('creative_name')->nullable();
            $table->text('creative_summary')->nullable();

            $table->dateTime('last_synced_at')->nullable();

            $table->timestamps();

            $table->index('status');
        });

        Schema::create('meta_insights', function (Blueprint $table) {
            $table->id();

            // campaign / adset / ad. One table rather than three, because every
            // figure is the same figure at a different altitude and three tables
            // would be three copies of every query in 12.13.
            $table->string('level', 16);
            $table->string('entity_id', 64);
            $table->date('date');

            $table->decimal('spend', 15, 2)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('reach')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);

            // Meta's own computed rates rather than ours. They are not simple
            // division at their end — clicks are deduplicated and impressions
            // are attributed over a window — so recomputing them here would
            // produce figures that disagree with Ads Manager, and the person
            // holding both screens believes Ads Manager.
            $table->decimal('ctr', 8, 4)->nullable();
            $table->decimal('cpc', 15, 4)->nullable();
            $table->decimal('cpm', 15, 4)->nullable();

            $table->unsignedInteger('leads')->default(0);
            $table->unsignedInteger('conversions')->default(0);

            // Per row, from the ad account. An agency running one client in USD
            // and another in BDT must never have the two added together, and a
            // row that did not carry its own currency would invite exactly that.
            $table->string('currency', 3)->nullable();

            // When Meta was asked. Meta's attribution windows move a day's
            // figures for up to 72 hours, so a spend number is only meaningful
            // beside the moment it was read — 12.13's dashboard says so rather
            // than implying a live figure.
            $table->dateTime('read_at');

            $table->timestamps();

            // One row per entity per day. A re-read of a day whose figures have
            // moved updates it; without this a nightly sync would add a second
            // Tuesday every Tuesday.
            $table->unique(['level', 'entity_id', 'date'], 'meta_insights_entity_day_unique');
            // "This campaign, over this period" — the shape every chart asks.
            $table->index(['entity_id', 'date']);
            $table->index('date');
        });

        Schema::table('meta_ad_accounts', function (Blueprint $table) {
            // How far the daily figures have been read. A date rather than a
            // timestamp, because insights are days: Meta reports a day in the ad
            // account's own timezone, and an hour-precision mark would make
            // "have we got Tuesday" unanswerable.
            //
            // Written **once, at the end of a run** — the rule Phase 8's pull
            // cursor learned the hard way. Advancing it per page would leave the
            // mark past days a failed run never fetched, and those days would
            // never be asked for again.
            $table->date('insights_synced_through')->nullable()->after('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('meta_ad_accounts', function (Blueprint $table) {
            $table->dropColumn('insights_synced_through');
        });

        Schema::dropIfExists('meta_insights');
        Schema::dropIfExists('meta_ads');
        Schema::dropIfExists('meta_ad_sets');
        Schema::dropIfExists('meta_campaigns');
    }
};
