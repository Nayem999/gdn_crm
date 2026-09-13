<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One person's arrangement of their own dashboard.
 *
 * A row per widget rather than one JSON blob per user. Dragging changes a
 * position, which is an update to one row; a blob would rewrite the whole
 * layout on every drag and lose a concurrent change from another tab. It also
 * lets the foreign key do its job — removing a report takes its widgets with
 * it, instead of leaving a layout pointing at a report that is gone.
 *
 * Per user, always. A shared dashboard would need a second question — whose
 * records does it count — and the honest answer is "the reader's", which is
 * what a personal dashboard already is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The report this widget draws. Cascading: a widget whose report
            // has gone has nothing to show, and an empty frame on somebody's
            // dashboard is worse than one fewer widget.
            $table->foreignId('report_id')->constrained('reports')->cascadeOnDelete();

            // Null means "use the report's name", so renaming the report
            // renames the widget — which is what somebody expects until they
            // deliberately title it otherwise.
            $table->string('title')->nullable();

            // Null means "draw it the way the report says". Set when somebody
            // wants the same report as a gauge here and a table there.
            $table->string('chart_type', 32)->nullable();

            // Columns out of three. A number rather than a CSS class, so the
            // layout survives a redesign.
            $table->unsignedTinyInteger('width')->default(1);

            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['user_id', 'position']);

            // One widget per report per person. Somebody wanting the same
            // report twice wants two different reports, and without this a
            // double-click adds a duplicate nobody notices until they remove
            // one and the other stays.
            $table->unique(['user_id', 'report_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_widgets');
    }
};
