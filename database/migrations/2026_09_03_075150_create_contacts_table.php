<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();

            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('job_title')->nullable();
            $table->string('department')->nullable();

            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('mobile', 50)->nullable();

            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 100)->nullable();

            $table->text('description')->nullable();

            // A contact may exist before anyone works out which organisation
            // they belong to, so this is nullable. Nulled on delete rather than
            // cascaded: removing an account must not take its people with it.
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();

            // One primary contact per account, enforced in
            // SetPrimaryContactAction rather than by a unique index: MySQL
            // treats every NULL account_id as distinct, so a partial unique
            // index cannot express "one per account, none without an account".
            $table->boolean('is_primary')->default(false);

            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index('last_name');
            $table->index('email');
            $table->index('department');
            $table->index('country');
            // The two pairs the list screen actually queries: an account's
            // people, and one owner's contacts newest first.
            $table->index(['account_id', 'is_primary']);
            $table->index(['owner_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
