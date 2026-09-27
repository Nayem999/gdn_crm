<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The people on file a lead is linked to — any number of them.
 *
 * Replaces the single `leads.contact_id` column: a lead from an existing
 * customer often involves more than one person there (the buyer, the person
 * who signs, the person who uses it). Whatever that column held is carried
 * over as each lead's first contact before it is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_contacts', function (Blueprint $table) {
            $table->id();

            // Scoped directly, like lead_assignees: a query against this table
            // must not cross a workspace just because nobody joined to leads.
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();

            // cascadeOnUpdate for the same reason lead_assignees has it: the
            // duplicate tests renumber a lead, and MySQL checks the constraint
            // on any change to the parent key.
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();

            // Removing the person just removes the link.
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();

            // The order they were listed on the form; the first is the one
            // conversion offers by default.
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->unique(['tenant_id', 'lead_id', 'contact_id']);
        });

        if (Schema::hasColumn('leads', 'contact_id')) {
            DB::table('leads')->whereNotNull('contact_id')->orderBy('id')
                ->each(function (object $lead): void {
                    DB::table('lead_contacts')->insert([
                        'tenant_id' => $lead->tenant_id,
                        'lead_id' => $lead->id,
                        'contact_id' => $lead->contact_id,
                        'position' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });

            Schema::table('leads', function (Blueprint $table) {
                $table->dropConstrainedForeignId('contact_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('contact_id')->nullable()->after('account_id')->constrained('contacts')->nullOnDelete();
        });

        // Only the first person survives the trip back to one column.
        DB::table('lead_contacts')->where('position', 0)->orderBy('id')
            ->each(function (object $link): void {
                DB::table('leads')->where('id', $link->lead_id)->update(['contact_id' => $link->contact_id]);
            });

        Schema::dropIfExists('lead_contacts');
    }
};
