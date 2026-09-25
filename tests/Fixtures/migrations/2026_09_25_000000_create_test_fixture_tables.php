<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The stand-in tables a few suites test against, created with the test
 * database rather than inside a test.
 *
 * A CREATE TABLE inside a test commits the transaction RefreshDatabase wraps it
 * in. Laravel notices and re-migrates the whole database before the next test,
 * which drops the stand-in table, so the next test creates it again — a full
 * migrate:fresh per test, about ten seconds each, and most of CI's runtime.
 *
 * Loaded only by tests/TestCase.php; the application never sees it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_view_records', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('stage')->default('new');
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->decimal('value', 15, 2)->default(0);
            $table->date('closes_on')->nullable();
            $table->boolean('is_starred')->default(false);
            $table->timestamps();

            $table->index('stage');
        });

        Schema::create('filterable_records', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->integer('score')->nullable();
            $table->date('closes_on')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_active')->nullable();
            $table->timestamps();
        });

        foreach (['access_level_records', 'fixture_records', 'team_scope_records'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->foreignId('owner_id');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['data_view_records', 'filterable_records', 'access_level_records', 'fixture_records', 'team_scope_records'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
