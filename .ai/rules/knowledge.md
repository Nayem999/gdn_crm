---
paths:
  - 'app/Domain/Knowledge/**'
  - 'app/Livewire/Knowledge/**'
  - 'resources/views/livewire/knowledge/**'
---

# Knowledge base

## Search is scored in SQL, deliberately not FULLTEXT
`ArticleSearch` builds a weighted `CASE` sum over title, keywords, excerpt and
body. Two reasons it is not a FULLTEXT index:

- **The engines disagree.** MySQL and MariaDB use different minimum token
  lengths and stopword lists, so the same query ranks differently on the two
  this application is expected to run on — and a three-letter product name is
  unsearchable on one of them.
- **Relevance has to be assertable.** A weighted score is something a test can
  state exactly; an engine's internal ranking is not, and "search relevance" is
  the brief's test for this task.

The weights are constants on the class so a test names them rather than
repeating numbers: `TITLE_PHRASE` (100) > `TITLE_TERM` (40) >
`KEYWORD_TERM` (30) > `EXCERPT_TERM` (10) > `BODY_TERM` (5). The body is the
weakest signal because a long article mentions everything once.

`query()` returns **null** when there is nothing worth searching for, rather
than an empty result — a screen can then say "you typed nothing" instead of
"nothing matched". Terms are capped at `MAX_TERMS` so a pasted paragraph does
not become a hundred-branch expression, and the LIKE value is escaped: a
customer searching for `100%` means the character.

## `keywords` is the column that makes search work
Nobody searches for "Power supply faults"; they search for "won't turn on". The
`keywords` column holds the words people type that the article does not contain,
and is weighted just below a title hit for exactly that reason.

## Articles are not access-level scoped; drafts are status-scoped
An article is company knowledge, not somebody's record — `ScopesByAccessLevel`
is deliberately absent, because an agent who could only see the articles they
wrote would be looking things up in an empty library.

What *is* gated is state: `scopeReadableBy()` returns published articles only
unless the viewer holds `knowledge.update`. An agent must not find an unfinished
answer and repeat it to a customer.

## Publishing is its own permission and its own action
`PublishArticleAction` is the only writer of `status` and `published_at`, the
same arrangement as `ChangeTicketStatusAction`. `published_at` is stamped the
first time and kept: an article unpublished for a correction and published again
was first published when it first was, and a date that jumped forward would
reorder every list sorting by it.

`knowledge.publish` is separate from `knowledge.create`/`update` because writing
a draft and putting it in front of customers are different acts. The publish
controls live on the **article page**, never the form — a publish button on a
form is one somebody presses before reading what they wrote.

## The slug stops following the title once published
While an article is a draft the slug is recut from the title on every save. Once
published it is frozen: the URL is in somebody's bookmarks and in the reply an
agent sent a customer last week, and a tidier slug is not worth breaking those.
Uniqueness counts up (`printer-setup-2`) rather than appending randomness,
because a readable URL is most of the point.

Routes bind `{article:slug}`, and the fixed segments (`/knowledge/sections`,
`/knowledge/new`) are registered **before** it so an article slugged "sections"
cannot shadow a page.

## Sections go one level deep, and removing one never removes articles
`SaveCategoryAction` refuses a grandchild, a cycle, and demoting a section that
has children of its own. Deeper nesting is how a library becomes unsearchable.

`DeleteCategoryAction` unfiles the articles and promotes the subsections rather
than cascading. An article that vanished because somebody tidied the sections is
a support failure nobody would trace back to this.

## The body is escaped, always
The article page prints the body with `{{ }}` and `whitespace-pre-line`. A
knowledge base that rendered HTML would be a stored-XSS hole with an editor
attached, and the authors here are colleagues rather than developers.

## Feedback is two counters, incremented in the database
`helpful_count` and `unhelpful_count`, not a single score: "40 of 50" and "4 of
5" are different amounts of evidence. `increment()` rather than
read-modify-write, so two readers answering at once do not lose a vote.
`helpfulness()` returns null when nobody has answered — not nought, which would
read as "everyone said no".
