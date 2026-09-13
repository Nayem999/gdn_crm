<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The knowledge base: what we already know, written down once.
 *
 * Search is scored in SQL from the columns below rather than through a FULLTEXT
 * index. Two reasons: MySQL and MariaDB disagree about minimum token length and
 * stopwords, so the same query ranks differently on the two engines this
 * application is expected to run on; and a weighted score over title, keywords,
 * excerpt and body is something a test can assert exactly, which "relevance"
 * from an engine's own ranking is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_categories', function (Blueprint $table) {
            $table->id();

            // One level of nesting: a section and its subsections. Deeper than
            // that and nobody finds anything, which is the opposite of the
            // point.
            $table->foreignId('parent_id')->nullable()->constrained('kb_categories')->nullOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['parent_id', 'position']);
        });

        Schema::create('kb_articles', function (Blueprint $table) {
            $table->id();

            // Nullable: an article being drafted does not yet have to belong
            // anywhere, and removing a category must not take its articles.
            $table->foreignId('kb_category_id')->nullable()->constrained('kb_categories')->nullOnDelete();

            $table->string('title');
            $table->string('slug')->unique();

            // The line shown in a search result. Held separately from the body
            // so a result list does not have to guess where to cut.
            $table->string('excerpt', 500)->nullable();

            $table->longText('body');

            // The words people actually type that the article does not contain
            // — "won't turn on" for an article titled "Power supply faults".
            // Weighted highly in search for that reason.
            $table->string('keywords', 500)->nullable();

            $table->string('status', 32)->default('draft');
            $table->timestamp('published_at')->nullable();

            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedInteger('view_count')->default(0);

            // What readers said when asked whether it helped. Two counters
            // rather than a score, because "40 of 50" and "4 of 5" are
            // different amounts of evidence and a single number loses that.
            $table->unsignedInteger('helpful_count')->default(0);
            $table->unsignedInteger('unhelpful_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // The two ways the list is read: what is published, and what is in
            // this category.
            $table->index(['status', 'published_at']);
            $table->index(['kb_category_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_articles');
        Schema::dropIfExists('kb_categories');
    }
};
