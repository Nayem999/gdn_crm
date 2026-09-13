<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Knowledge\Actions\DeleteCategoryAction;
use App\Domain\Knowledge\Actions\PublishArticleAction;
use App\Domain\Knowledge\Actions\RecordArticleFeedbackAction;
use App\Domain\Knowledge\Actions\SaveCategoryAction;
use App\Domain\Knowledge\Actions\UpdateArticleAction;
use App\Domain\Knowledge\ArticleSearch;
use App\Domain\Knowledge\DTOs\ArticleData;
use App\Domain\Knowledge\Enums\ArticleStatus;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Knowledge\Models\Category;
use App\Livewire\Knowledge\ArticleForm;
use App\Livewire\Knowledge\ArticleShow;
use App\Livewire\Knowledge\KnowledgeIndex;
use App\Livewire\Knowledge\KnowledgeSections;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Somebody who runs the knowledge base.
 *
 * @param  array<int, string>  $permissions
 */
function knowledgeAuthor(?array $permissions = null): User
{
    $permissions ??= [
        'knowledge.view', 'knowledge.create', 'knowledge.update',
        'knowledge.publish', 'knowledge.delete',
    ];

    return knowledgeReader($permissions);
}

/**
 * @param  array<int, string>  $permissions
 */
function knowledgeReader(array $permissions = ['knowledge.view']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-13 09:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

// -- Search relevance ----------------------------------------------------------

test('an article titled after the search comes first', function () {
    $title = Article::factory()->published()
        ->saying('Printer will not connect', 'Some unrelated body text about other things.')
        ->create();

    $body = Article::factory()->published()
        ->saying('Network overview', 'A printer sometimes will not connect to the office network.')
        ->create();

    $results = app(ArticleSearch::class)->search('printer will not connect');

    // The whole phrase in the title outweighs every term appearing in a body.
    expect($results->first()->id)->toBe($title->id)
        ->and($results->pluck('id')->all())->toContain($body->id);
});

test('the weights rank title over keywords over excerpt over body', function () {
    $search = app(ArticleSearch::class);

    expect(ArticleSearch::TITLE_PHRASE)->toBeGreaterThan(ArticleSearch::TITLE_TERM)
        ->and(ArticleSearch::TITLE_TERM)->toBeGreaterThan(ArticleSearch::KEYWORD_TERM)
        ->and(ArticleSearch::KEYWORD_TERM)->toBeGreaterThan(ArticleSearch::EXCERPT_TERM)
        ->and(ArticleSearch::EXCERPT_TERM)->toBeGreaterThan(ArticleSearch::BODY_TERM);

    $article = Article::factory()->published()
        ->saying('Power supply faults', 'Check the fuse.', keywords: 'dead, no power')
        ->create();

    // A keyword hit and a body hit are not worth the same, and the score says
    // which by how much.
    expect($search->score($article, 'dead'))->toBe(ArticleSearch::KEYWORD_TERM)
        ->and($search->score($article, 'fuse'))->toBe(ArticleSearch::BODY_TERM);
});

test('keywords find an article that does not say the words', function () {
    $article = Article::factory()->published()
        ->saying('Power supply faults', 'Replace the internal fuse.', keywords: "won't turn on, dead, no power")
        ->create();

    Article::factory()->published()->saying('Something else', 'Nothing relevant here.')->create();

    // The whole reason the column exists: nobody searches for "power supply
    // faults", they search for what happened to them.
    expect(app(ArticleSearch::class)->search('dead')->pluck('id')->all())->toBe([$article->id]);
});

test('a more relevant article outranks a more helpful one', function () {
    $relevant = Article::factory()->published()
        ->saying('Printer setup', 'Body.')
        ->create();

    Article::factory()->published()
        ->saying('Something else', 'Mentions printer once.')
        ->foundHelpful(500)
        ->create();

    expect(app(ArticleSearch::class)->search('printer')->first()->id)->toBe($relevant->id);
});

test('helpfulness breaks a tie between equally relevant articles', function () {
    $liked = Article::factory()->published()
        ->saying('Printer setup', 'Body.')
        ->foundHelpful(20)
        ->create();

    Article::factory()->published()
        ->saying('Printer setup', 'Body.')
        ->create();

    // Two equally relevant articles are not equally good answers.
    expect(app(ArticleSearch::class)->search('printer setup')->first()->id)->toBe($liked->id);
});

test('an article matching nothing is not in the results', function () {
    Article::factory()->published()->saying('Printer will not connect', 'Body.')->create();
    Article::factory()->published()->saying('Invoicing', 'Nothing to do with hardware.')->create();

    expect(app(ArticleSearch::class)->search('printer'))->toHaveCount(1);
});

test('a search with nothing worth searching for returns no query at all', function (string $query) {
    // Null, not an empty result: a screen can then say "you typed nothing"
    // rather than "nothing matched".
    expect(app(ArticleSearch::class)->query($query))->toBeNull();
})->with(['empty' => [''], 'spaces' => ['   '], 'one letter' => ['a']]);

test('a search only matches what the reader may read', function () {
    Article::factory()->saying('Printer will not connect', 'A draft.')->create();

    expect(app(ArticleSearch::class)->search('printer', knowledgeReader()))->toBeEmpty()
        ->and(app(ArticleSearch::class)->search('printer', knowledgeAuthor()))->toHaveCount(1);
});

test('a LIKE wildcard in the search is a character, not a wildcard', function () {
    $article = Article::factory()->published()->saying('100% duty cycle', 'Body.')->create();
    Article::factory()->published()->saying('Anything at all', 'Another body.')->create();

    // Unescaped, "100%" would match every article whose title starts with 100
    // — and a bare "%" would match the library.
    expect(app(ArticleSearch::class)->search('100%')->pluck('id')->all())->toBe([$article->id]);
});

test('a pasted paragraph does not become a hundred-branch query', function () {
    expect(app(ArticleSearch::class)->terms(implode(' ', range(1, 50))))
        ->toHaveCount(ArticleSearch::MAX_TERMS);
});

// -- Publish and unpublish -----------------------------------------------------

test('an article starts as a draft', function () {
    $article = Article::factory()->create();

    expect($article->status())->toBe(ArticleStatus::Draft)
        ->and($article->published_at)->toBeNull()
        ->and($article->isPublished())->toBeFalse();
});

test('publishing stamps the date', function () {
    $article = Article::factory()->create();

    expect(app(PublishArticleAction::class)($article, ArticleStatus::Published))->toBeTrue();

    expect($article->fresh()->isPublished())->toBeTrue()
        ->and($article->fresh()->published_at->format('Y-m-d H:i'))->toBe('2026-09-13 09:00');
});

test('unpublishing and publishing again keeps the first date', function () {
    $article = Article::factory()->create();
    app(PublishArticleAction::class)($article, ArticleStatus::Published);

    Carbon::setTestNow('2026-10-01 09:00:00');
    app(PublishArticleAction::class)($article->fresh(), ArticleStatus::Draft);
    app(PublishArticleAction::class)($article->fresh(), ArticleStatus::Published);

    // A date that jumped forward would reorder every list that sorts by it.
    expect($article->fresh()->published_at->format('Y-m-d'))->toBe('2026-09-13');
});

test('publishing to the status it already has reports no change', function () {
    $article = Article::factory()->published()->create();

    expect(app(PublishArticleAction::class)($article, ArticleStatus::Published))->toBeFalse();
});

test('an unpublished article is invisible to a reader', function () {
    Article::factory()->saying('Draft answer', 'Not finished.')->create();
    Article::factory()->published()->saying('Finished answer', 'Ready.')->create();

    $visible = Article::query()->readableBy(knowledgeReader())->pluck('title')->all();

    // An agent looking something up must not find an unfinished answer and
    // repeat it to a customer.
    expect($visible)->toBe(['Finished answer']);
});

test('an editor sees drafts', function () {
    Article::factory()->saying('Draft answer', 'Not finished.')->create();

    expect(Article::query()->readableBy(knowledgeAuthor())->count())->toBe(1);
});

test('the slug follows the title while it is a draft and stops when published', function () {
    $article = Article::factory()->saying('First title', 'Body.')->create();

    app(UpdateArticleAction::class)($article, ArticleData::fromArray([
        'title' => 'Second title',
        'body' => 'Body.',
    ]));

    expect($article->fresh()->slug)->toBe('second-title');

    app(PublishArticleAction::class)($article->fresh(), ArticleStatus::Published);
    $published = $article->fresh()->slug;

    app(UpdateArticleAction::class)($article->fresh(), ArticleData::fromArray([
        'title' => 'Third title',
        'body' => 'Body.',
    ]));

    // The URL is in somebody's bookmarks and in a reply sent last week.
    expect($article->fresh()->slug)->toBe($published);
});

test('two articles with the same title get different slugs', function () {
    $first = Article::query()->create([
        'title' => 'Printer setup',
        'slug' => Article::slugFor('Printer setup'),
        'body' => 'Body.',
    ]);

    $second = Article::query()->create([
        'title' => 'Printer setup',
        'slug' => Article::slugFor('Printer setup'),
        'body' => 'Body.',
    ]);

    expect($first->slug)->toBe('printer-setup')
        ->and($second->slug)->toBe('printer-setup-2');
});

// -- Categories ----------------------------------------------------------------

test('a section can hold subsections, one level deep', function () {
    $parent = Category::factory()->named('Hardware')->create();
    $child = app(SaveCategoryAction::class)(new Category, 'Printers', $parent->id);

    expect($child->parent_id)->toBe($parent->id)
        ->and($child->path())->toBe('Hardware / Printers');

    // Deeper than that and nobody finds anything.
    expect(fn () => app(SaveCategoryAction::class)(new Category, 'Laser', $child->id))
        ->toThrow(RuntimeException::class);
});

test('a section cannot sit inside itself', function () {
    $category = Category::factory()->create();

    expect(fn () => app(SaveCategoryAction::class)($category, $category->name, $category->id))
        ->toThrow(RuntimeException::class);
});

test('a section with subsections cannot become one', function () {
    $parent = Category::factory()->create();
    Category::factory()->under($parent)->create();
    $other = Category::factory()->create();

    expect(fn () => app(SaveCategoryAction::class)($parent, $parent->name, $other->id))
        ->toThrow(RuntimeException::class);
});

test('removing a section leaves its articles behind, unfiled', function () {
    $category = Category::factory()->create();
    $article = Article::factory()->in($category)->published()->create();

    app(DeleteCategoryAction::class)($category);

    // An article that vanished because somebody tidied the sections is a
    // support failure nobody would trace back to this.
    expect($article->fresh())->not->toBeNull()
        ->and($article->fresh()->kb_category_id)->toBeNull()
        ->and(Category::query()->whereKey($category->id)->exists())->toBeFalse();
});

test('removing a parent section promotes its subsections', function () {
    $parent = Category::factory()->create();
    $child = Category::factory()->under($parent)->create();

    app(DeleteCategoryAction::class)($parent);

    expect($child->fresh()->parent_id)->toBeNull();
});

// -- Feedback ------------------------------------------------------------------

test('feedback counts both answers separately', function () {
    $article = Article::factory()->published()->create();

    app(RecordArticleFeedbackAction::class)($article, true);
    app(RecordArticleFeedbackAction::class)($article, true);
    app(RecordArticleFeedbackAction::class)($article, false);

    $article = $article->fresh();

    // "40 of 50" and "4 of 5" are different amounts of evidence.
    expect($article->helpful_count)->toBe(2)
        ->and($article->unhelpful_count)->toBe(1)
        ->and($article->votes())->toBe(3)
        ->and($article->helpfulness())->toBe(66.7);
});

test('an article nobody has rated has no helpfulness, rather than nought', function () {
    expect(Article::factory()->published()->create()->helpfulness())->toBeNull();
});

// -- The screens ---------------------------------------------------------------

test('the library needs the view permission', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(KnowledgeIndex::class)
        ->assertForbidden();

    Livewire::actingAs(knowledgeReader())
        ->test(KnowledgeIndex::class)
        ->assertOk();
});

test('the library lists published articles and searches them', function () {
    Article::factory()->published()->saying('Printer will not connect', 'Body.')->create();
    Article::factory()->published()->saying('Invoicing questions', 'Body.')->create();

    Livewire::actingAs(knowledgeReader())
        ->test(KnowledgeIndex::class)
        ->assertSee('Printer will not connect')
        ->assertSee('Invoicing questions')
        ->set('search', 'printer')
        ->assertSee('Printer will not connect')
        ->assertDontSee('Invoicing questions');
});

test('the library does not show a reader a draft', function () {
    Article::factory()->saying('Unfinished answer', 'Body.')->create();

    Livewire::actingAs(knowledgeReader())
        ->test(KnowledgeIndex::class)
        ->assertDontSee('Unfinished answer');
});

test('browsing a section narrows the list', function () {
    $category = Category::factory()->named('Hardware')->create();
    Article::factory()->published()->in($category)->saying('Printer will not connect', 'Body.')->create();
    Article::factory()->published()->saying('Invoicing questions', 'Body.')->create();

    Livewire::actingAs(knowledgeReader())
        ->test(KnowledgeIndex::class)
        ->call('openSection', $category->id)
        ->assertSee('Printer will not connect')
        ->assertDontSee('Invoicing questions');
});

test('the form writes a draft and lands on the article', function () {
    $author = knowledgeAuthor();

    Livewire::actingAs($author)
        ->test(ArticleForm::class)
        ->set('title', 'Printer will not connect')
        ->set('body', 'Check the cable first.')
        ->set('keywords', 'dead, no power')
        ->call('save')
        ->assertHasNoErrors();

    $article = Article::query()->firstOrFail();

    expect($article->title)->toBe('Printer will not connect')
        ->and($article->slug)->toBe('printer-will-not-connect')
        ->and($article->status())->toBe(ArticleStatus::Draft)
        ->and($article->author_id)->toBe($author->id);
});

test('the form insists on a title and a body', function () {
    Livewire::actingAs(knowledgeAuthor())
        ->test(ArticleForm::class)
        ->set('title', '')
        ->set('body', '')
        ->call('save')
        ->assertHasErrors(['title' => 'required', 'body' => 'required']);
});

test('the form has no publish control', function () {
    $article = Article::factory()->create();

    // A publish button on a form is one somebody presses before reading what
    // they wrote.
    Livewire::actingAs(knowledgeAuthor())
        ->test(ArticleForm::class, ['article' => $article])
        ->assertDontSeeHtml('wire:model="status"')
        // The word appears in the note explaining where publishing happens, so
        // the assertion is about the control rather than the string.
        ->assertDontSeeHtml('wire:click="publish"');
});

test('writing needs the create permission', function () {
    Livewire::actingAs(knowledgeReader())
        ->test(ArticleForm::class)
        ->assertForbidden();
});

test('the article page publishes and unpublishes', function () {
    $article = Article::factory()->create();

    $screen = Livewire::actingAs(knowledgeAuthor())->test(ArticleShow::class, ['article' => $article]);

    $screen->call('publish');
    expect($article->fresh()->isPublished())->toBeTrue();

    $screen->call('unpublish');
    expect($article->fresh()->status())->toBe(ArticleStatus::Draft);
});

test('publishing is its own permission', function () {
    $article = Article::factory()->create();
    $writer = knowledgeReader(['knowledge.view', 'knowledge.create', 'knowledge.update']);

    // Writing a draft and putting it in front of customers are different acts.
    Livewire::actingAs($writer)
        ->test(ArticleShow::class, ['article' => $article])
        ->call('publish')
        ->assertForbidden();

    expect($article->fresh()->isPublished())->toBeFalse();
});

test('a reader cannot open a draft by guessing its slug', function () {
    $article = Article::factory()->create();

    Livewire::actingAs(knowledgeReader())
        ->test(ArticleShow::class, ['article' => $article])
        ->assertForbidden();
});

test('opening an article counts a view', function () {
    $article = Article::factory()->published()->create();

    Livewire::actingAs(knowledgeReader())->test(ArticleShow::class, ['article' => $article]);

    expect($article->fresh()->view_count)->toBe(1);
});

test('the article page records feedback once', function () {
    $article = Article::factory()->published()->create();

    Livewire::actingAs(knowledgeReader())
        ->test(ArticleShow::class, ['article' => $article])
        ->call('markHelpful', true)
        ->call('markHelpful', true);

    expect($article->fresh()->helpful_count)->toBe(1);
});

test('the body is escaped, never rendered as markup', function () {
    $article = Article::factory()->published()
        ->saying('Careful', '<script>alert(1)</script>')
        ->create();

    // A knowledge base that rendered HTML would be a stored-XSS hole with an
    // editor attached.
    Livewire::actingAs(knowledgeReader())
        ->test(ArticleShow::class, ['article' => $article])
        ->assertDontSeeHtml('<script>alert(1)</script>');
});

test('the sections screen saves and removes a section', function () {
    Livewire::actingAs(knowledgeAuthor())
        ->test(KnowledgeSections::class)
        ->call('create')
        ->set('name', 'Hardware')
        ->call('save')
        ->assertHasNoErrors();

    $category = Category::query()->firstOrFail();

    expect($category->name)->toBe('Hardware')
        ->and($category->slug)->toBe('hardware');

    Livewire::actingAs(knowledgeAuthor())
        ->test(KnowledgeSections::class)
        ->call('delete', $category->id);

    expect(Category::query()->whereKey($category->id)->exists())->toBeFalse();
});

test('the sections screen refuses nesting that is too deep', function () {
    $parent = Category::factory()->create();
    $child = Category::factory()->under($parent)->create();

    Livewire::actingAs(knowledgeAuthor())
        ->test(KnowledgeSections::class)
        ->call('create')
        ->set('name', 'Laser')
        ->set('parentId', (string) $child->id)
        ->call('save')
        ->assertHasErrors(['parentId']);
});

test('arranging sections needs the edit permission', function () {
    Livewire::actingAs(knowledgeReader())
        ->test(KnowledgeSections::class)
        ->call('create')
        ->assertForbidden();
});

// -- Routes --------------------------------------------------------------------

test('the knowledge pages are reachable', function () {
    $author = knowledgeAuthor();
    $article = Article::factory()->published()->create();

    $this->actingAs($author)->get(route('knowledge.index'))->assertOk();
    $this->actingAs($author)->get(route('knowledge.sections'))->assertOk();
    $this->actingAs($author)->get(route('knowledge.create'))->assertOk();
    $this->actingAs($author)->get(route('knowledge.show', $article->slug))->assertOk();
    $this->actingAs($author)->get(route('knowledge.edit', $article->slug))->assertOk();
});

test('an article is reached by its slug, not its id', function () {
    $article = Article::factory()->published()->saying('Printer will not connect', 'Body.')->create();

    // The URL is pasted into replies to customers and should say what it points
    // at.
    expect(route('knowledge.show', $article->slug))->toContain($article->slug);

    $this->actingAs(knowledgeReader())->get(route('knowledge.show', $article->slug))->assertOk();
});

test('the fixed knowledge routes are not shadowed by a slug', function () {
    // An article slugged "sections" must not take over the sections page.
    Article::factory()->published()->saying('Sections', 'Body.')->create(['slug' => 'sections']);

    $this->actingAs(knowledgeAuthor())
        ->get(route('knowledge.sections'))
        ->assertOk()
        ->assertSee('One level of nesting');
});
