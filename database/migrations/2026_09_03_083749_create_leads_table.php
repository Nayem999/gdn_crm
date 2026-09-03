<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();

            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('job_title')->nullable();

            // The organisation as the lead typed it. Deliberately a string and
            // not an accounts foreign key: a lead has not been matched to an
            // account yet — that is what conversion does in task 2.6.
            $table->string('company_name')->nullable();

            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('mobile', 50)->nullable();
            $table->string('website')->nullable();

            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 100)->nullable();

            $table->string('status')->default('new');
            $table->string('source')->nullable();

            // What the lead might be worth. Money is always DECIMAL.
            $table->decimal('estimated_value', 15, 2)->nullable();

            $table->text('description')->nullable();

            // When the status last moved, so "sitting in New for three weeks"
            // is answerable without reading the audit trail.
            $table->dateTime('status_changed_at')->nullable();

            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index('last_name');
            $table->index('company_name');
            $table->index('email');
            $table->index('source');
            $table->index('estimated_value');
            // The board and the list both read these pairs.
            $table->index(['status', 'created_at']);
            $table->index(['owner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
