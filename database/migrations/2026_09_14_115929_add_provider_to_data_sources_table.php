<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            // Which integration owns this source, or null for the ordinary kind
            // an administrator sets up by hand.
            //
            // It exists so Meta's webhooks can use this pipeline rather than
            // stand up a second one: the delivery log, the replay, the health
            // panel and the retry button are all worth more than the freedom to
            // do it differently. What a provider-owned source changes is only
            // how a delivery is authenticated and who processes it — Meta signs
            // with the app secret rather than a key we issued, and a lead-ads
            // event carries an id to fetch rather than a record to map.
            //
            // A provider's source is not editable in the data-source screens.
            // It is created when the integration connects and removed when it
            // disconnects; letting somebody point it at another module by hand
            // would be a way to make Meta write into anything.
            $table->string('provider', 40)->nullable()->after('type');

            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropIndex(['provider']);
            $table->dropColumn('provider');
        });
    }
};
