<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // When the installation wizard finished. Null means the wizard has
            // never run, which is the one state in which it opens.
            //
            // It lives here rather than in settings because this is a
            // single-organisation install: the company row *is* the
            // organisation, and settings are administrator-editable, which this
            // must never be.
            //
            // dateTime rather than timestamp: MySQL gives the first NOT NULL
            // TIMESTAMP column an implicit ON UPDATE CURRENT_TIMESTAMP.
            $table->dateTime('installed_at')->nullable()->after('fiscal_year_start_month');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('installed_at');
        });
    }
};
