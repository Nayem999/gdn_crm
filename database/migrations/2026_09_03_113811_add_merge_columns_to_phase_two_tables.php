<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a merged-away record went.
 *
 * A merge soft-deletes the loser and points it at the survivor rather than
 * destroying it: its audit trail stays attributable to the record the events
 * actually happened to, and the survivor can show that history. Deleting the
 * row would leave those entries pointing at nothing.
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $tables = ['leads', 'contacts', 'accounts'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                // nullOnDelete rather than cascade: losing the survivor must
                // never quietly take the merge record with it.
                $table->foreignId('merged_into_id')->nullable()->constrained($name)->nullOnDelete();
                $table->timestamp('merged_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('merged_into_id');
                $table->dropColumn('merged_at');
            });
        }
    }
};
