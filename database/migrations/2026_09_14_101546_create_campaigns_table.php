<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('type');
            $table->string('status');

            $table->text('description')->nullable();

            // A campaign runs between two dates. Both nullable: one being
            // planned has neither yet, and one that is still running has no end.
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            // Money is always DECIMAL, never FLOAT.
            $table->decimal('budget', 15, 2)->nullable();
            // What it has actually cost. Meta spend is added to this in 12.7;
            // a campaign that runs nowhere near Meta still has a cost somebody
            // types in, which is the whole reason this is a CRM field rather
            // than a synced one.
            $table->decimal('actual_cost', 15, 2)->nullable();
            $table->decimal('expected_revenue', 15, 2)->nullable();

            // The company's own code for it, printed on briefs and invoices.
            $table->string('code', 60)->nullable()->unique();

            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index('name');
            $table->index('type');
            $table->index('status');
            $table->index('start_date');
            $table->index('end_date');
            // The two pairs every campaign list and report reaches for.
            $table->index(['status', 'start_date']);
            $table->index(['owner_id', 'status']);
        });

        // What a record came from. Nulled rather than cascaded on delete:
        // removing a campaign must never take the customers it won with it, and
        // a lead whose campaign has been deleted is still a lead.
        //
        // On all four, because attribution has to survive conversion — §13 and
        // §37 of the brief. A lead becomes an account, a contact and a deal,
        // and each of them is a record somebody will later ask "where did this
        // customer come from?" of.
        foreach (['leads', 'contacts', 'accounts', 'deals'] as $module) {
            Schema::table($module, function (Blueprint $table) {
                $table->foreignId('campaign_id')->nullable()->after('owner_id')
                    ->constrained('campaigns')->nullOnDelete();

                $table->index('campaign_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['leads', 'contacts', 'accounts', 'deals'] as $module) {
            Schema::table($module, function (Blueprint $table) {
                $table->dropForeign([$module.'_campaign_id_foreign']);
                $table->dropColumn('campaign_id');
            });
        }

        Schema::dropIfExists('campaigns');
    }
};
