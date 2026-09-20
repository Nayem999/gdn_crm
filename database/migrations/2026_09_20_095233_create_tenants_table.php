<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The workspace every other row belongs to.
 *
 * This installation was built for one business and is being opened to many, in
 * one shared database. That decision puts the burden on the application: with
 * a database per customer, isolation is a fact of the schema, and here it is a
 * property of every query we write. So it is made structural where it can be —
 * a global scope that cannot be forgotten, a stamp on create, unique indexes
 * that are unique *per workspace* — rather than remembered at each call site.
 *
 * The first tenant is created here and every existing row is assigned to it.
 * The business already using this installation does not become a second-class
 * citizen of its own CRM: it becomes tenant one, and nothing it can see
 * changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            /**
             * How a request finds its workspace when nobody is logged in: the
             * subdomain, and the part of a public URL that says whose form,
             * whose chat widget, whose webhook this is.
             */
            $table->string('slug', 64)->unique();

            /**
             * Suspended rather than deleted, because a customer who stops
             * paying still owns their data and usually comes back. Read on
             * every request, so it is a column and not a join.
             */
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });

        // The business already here. Created in the migration rather than a
        // seeder: every table that gains a tenant_id below needs something to
        // point at, and a seeder that has not been run yet would leave the
        // installation with rows belonging to no one.
        $id = DB::table('tenants')->insertGetId([
            'name' => (string) (DB::table('companies')->value('name') ?? 'Workspace'),
            'slug' => 'default',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('users', function (Blueprint $table) {
            // Nullable for the length of this migration only — the backfill
            // below fills it, and a later migration makes it required once
            // every model has been moved across.
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        DB::table('users')->update(['tenant_id' => $id]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });

        Schema::dropIfExists('tenants');
    }
};
