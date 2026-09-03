<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();

            $table->string('industry')->nullable();
            $table->string('size')->nullable();
            // Money is always DECIMAL, never FLOAT.
            $table->decimal('annual_revenue', 15, 2)->nullable();

            $table->string('website')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 100)->nullable();

            $table->text('description')->nullable();

            // A subsidiary points at its parent. Nulled rather than cascaded on
            // delete: removing a holding company must not silently take its
            // subsidiaries with it.
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();

            // Who the record belongs to, which is what ScopesByAccessLevel reads.
            // Restricted: a user with accounts must be reassigned, not deleted
            // out from under them.
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();

            $table->softDeletes();
            $table->timestamps();

            // Every field the filter builder exposes gets an index.
            $table->index('name');
            $table->index('industry');
            $table->index('size');
            $table->index('country');
            $table->index('annual_revenue');
            // The common pair: one owner's accounts, newest first.
            $table->index(['owner_id', 'created_at']);
            $table->index(['owner_id', 'industry']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
