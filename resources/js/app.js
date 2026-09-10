import './bootstrap';
import TomSelect from 'tom-select';
import Sortable from 'sortablejs';

/**
 * Turn a server-supplied option payload into the shape Tom Select expects.
 */
const normaliseOption = (option) => ({
    value: String(option.value ?? ''),
    text: String(option.label ?? option.text ?? ''),
    description: option.description ?? null,
    color: option.color ?? null,
    disabled: Boolean(option.disabled),
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('tomSelectField', (options = {}) => ({
        instance: null,
        page: 1,
        exhausted: false,
        // Our own in-flight flag: instance.loading only tracks loads Tom
        // Select started itself, not the paged ones we drive.
        fetching: false,

        init() {
            const remote = Boolean(options.searchMethod || options.searchUrl);

            const settings = {
                maxItems: options.multiple ? null : 1,
                placeholder: options.placeholder ?? undefined,
                // Tom Select 2 defaults dataAttr to null, so without this the
                // description and colour on each <option> never reach render().
                // It indexes element.dataset, so the key is "data" (the dataset
                // name for data-data), not the attribute name.
                dataAttr: 'data',
                // remove_button puts an × on each chip; clear_button puts one on
                // the control, which is how a single filter dropdown gets back
                // to "nothing chosen" (Tom Select will not offer an empty
                // option as a selectable row).
                plugins: options.multiple
                    ? ['remove_button']
                    : (options.clearable ? ['clear_button'] : []),
                render: {
                    option: (data, escape) => this.renderRow(data, escape),
                    item: (data, escape) => this.renderItem(data, escape),
                    option_create: (data, escape) =>
                        '<div class="create">Add <strong>' + escape(data.input) + '</strong>&hellip;</div>',
                    no_results: () => '<div class="no-results">No matches found</div>',
                },
                onChange: () => {
                    // Livewire only reacts to native events, not Tom Select's own.
                    this.$refs.select.dispatchEvent(new Event('change', { bubbles: true }));
                },
            };

            if (remote) {
                settings.valueField = 'value';
                settings.labelField = 'text';
                settings.searchField = ['text', 'description'];
                settings.loadThrottle = 300;
                settings.preload = options.preload ?? false;
                settings.shouldLoad = () => true;
                settings.load = (query, callback) => this.loadPage(query, 1, callback);
            }

            // Create-on-the-fly hands the typed term to the page, which owns the
            // modal. The select never guesses how to persist a new record.
            if (options.createEvent) {
                settings.create = (input, callback) => {
                    this.$dispatch(options.createEvent, { term: input });
                    callback();
                };
            }

            this.instance = new TomSelect(this.$refs.select, settings);

            if (remote) {
                this.watchForMoreResults();
            }

            if (options.dependsOn) {
                this.followParentField(options.dependsOn);
            }

            // The page can push a freshly created option in without re-rendering.
            this.$el.addEventListener('select-option-added', (event) => {
                const option = normaliseOption(event.detail ?? {});
                this.instance.addOption(option);
                this.instance.addItem(option.value);
            });
        },

        renderRow(data, escape) {
            const dot = data.color
                ? '<span class="ts-dot ts-dot-' + escape(data.color) + '"></span>'
                : '';
            const description = data.description
                ? '<span class="ts-description">' + escape(data.description) + '</span>'
                : '';

            return (
                '<div class="ts-rich">' +
                dot +
                '<span class="ts-labels"><span class="ts-label">' +
                escape(data.text) +
                '</span>' +
                description +
                '</span></div>'
            );
        },

        renderItem(data, escape) {
            const dot = data.color
                ? '<span class="ts-dot ts-dot-' + escape(data.color) + '"></span>'
                : '';

            return '<div>' + dot + escape(data.text) + '</div>';
        },

        /**
         * Fetch one page of server-side results. Pages append rather than
         * replace, so the list grows as the user scrolls.
         */
        async loadPage(query, page, callback) {
            try {
                // Tom Select hands over undefined on a preload and on an empty
                // box; send what the server can actually type-hint.
                const term = query ?? '';

                const payload = options.searchMethod
                    ? await this.$wire.call(options.searchMethod, term, page)
                    : await fetch(
                          options.searchUrl +
                              '?q=' +
                              encodeURIComponent(term) +
                              '&page=' +
                              page,
                          { headers: { Accept: 'application/json' } }
                      ).then((response) => response.json());

                const rows = (payload?.options ?? []).map(normaliseOption);

                this.page = page;
                this.exhausted = !payload?.hasMore || rows.length === 0;

                callback(rows);
            } catch (error) {
                this.exhausted = true;
                callback();
            }
        },

        /**
         * Infinite scroll inside the dropdown.
         *
         * Deliberately not instance.load(): that takes the *query string* and
         * replaces the whole option list through setupOptions, which would
         * throw away page one every time page two arrived. Pages are appended
         * with addOptions() instead.
         */
        watchForMoreResults() {
            const dropdown = this.instance.dropdown_content;

            dropdown.addEventListener('scroll', () => {
                if (this.exhausted || this.fetching) {
                    return;
                }

                const remaining =
                    dropdown.scrollHeight - dropdown.scrollTop - dropdown.clientHeight;

                if (remaining >= 80) {
                    return;
                }

                this.fetching = true;

                this.loadPage(this.instance.lastValue ?? '', this.page + 1, (rows) => {
                    this.fetching = false;

                    if (rows && rows.length) {
                        this.instance.addOptions(rows);
                        this.instance.refreshOptions(false);
                    }
                });
            });
        },

        /**
         * Cascading selects: when the parent changes, the child's options no
         * longer apply, so clear them and reload for the new parent.
         */
        followParentField(parentId) {
            const parent = document.getElementById(parentId);

            if (!parent) {
                return;
            }

            const syncToParent = (reload) => {
                if (parent.value === '') {
                    this.instance.disable();

                    return;
                }

                this.instance.enable();

                if (reload && (options.searchMethod || options.searchUrl)) {
                    // A string: instance.load() takes the query, not a callback.
                    this.instance.load('');
                }
            };

            parent.addEventListener('change', () => {
                this.instance.clear(true);
                this.instance.clearOptions();
                this.exhausted = false;
                this.page = 1;
                syncToParent(true);
            });

            syncToParent(false);
        },

        destroy() {
            this.instance?.destroy();
        },
    }));

    /**
     * A kanban board whose columns accept cards from each other. Each column
     * registers itself in the same Sortable group; dropping a card reports the
     * card id and the destination column's value back to Livewire, which is
     * where the move is validated and authorised.
     *
     * The card moves the instant it is dropped — Sortable has already put it
     * there — and the server's answer decides whether it stays. A refused move
     * is put back from the origin recorded on drag start rather than left for
     * the next render to relocate: morphdom moving a keyed node between two
     * different parents is precisely the case not to depend on.
     */
    window.Alpine.data('kanbanColumn', (config = {}) => ({
        instance: null,

        init() {
            this.instance = Sortable.create(this.$el, {
                group: config.group ?? 'kanban',
                draggable: '[data-card-id]',
                animation: 150,
                ghostClass: 'opacity-40',
                // A card already waiting on the server must not be dragged
                // again: the second answer would be applied to an origin the
                // first one had already changed.
                filter: '[data-card-pending]',

                onStart: (event) => {
                    // Where to put it back. Captured before the DOM moves.
                    event.item._kanbanOrigin = {
                        parent: event.from,
                        next: event.item.nextElementSibling,
                    };
                },

                onAdd: (event) => this.submit(event),
            });
        },

        /**
         * Hand the drop to Livewire and act on the answer.
         */
        async submit(event) {
            const card = event.item;
            const id = card.dataset.cardId;

            if (!id || !config.method) {
                return;
            }

            const origin = card._kanbanOrigin;
            const from = event.from;

            this.markPending(card, true);
            this.adjustCount(from, -1);
            this.adjustCount(this.$el, 1);

            let moved = false;

            try {
                moved = await this.$wire.call(config.method, Number(id), config.value);
            } catch (error) {
                // A refusal from the policy rejects the call rather than
                // returning; either way the card goes back.
                moved = false;
            }

            this.markPending(card, false);

            if (moved) {
                return;
            }

            this.adjustCount(from, 1);
            this.adjustCount(this.$el, -1);
            this.revert(card, origin);
        },

        revert(card, origin) {
            if (!origin || !origin.parent) {
                return;
            }

            // insertBefore with a null reference appends, which is what is
            // wanted when the card was last in its column.
            const next = origin.next && origin.next.parentNode === origin.parent
                ? origin.next
                : null;

            origin.parent.insertBefore(card, next);
        },

        markPending(card, pending) {
            if (pending) {
                card.setAttribute('data-card-pending', '');
                card.classList.add('opacity-50', 'pointer-events-none');

                return;
            }

            card.removeAttribute('data-card-pending');
            card.classList.remove('opacity-50', 'pointer-events-none');
        },

        /**
         * Nudge a column header's count while the move is in flight.
         *
         * The count only — a summed money figure is formatted server-side to
         * the configured separators and currency, and re-implementing that
         * here would drift from it. The sum arrives correct with the re-render.
         */
        adjustCount(list, delta) {
            const counter = list?.closest('[data-board-column]')?.querySelector('[data-board-count]');

            if (!counter) {
                return;
            }

            const next = Math.max(0, Number(counter.dataset.boardCount || 0) + delta);

            counter.dataset.boardCount = String(next);
            counter.textContent = next.toLocaleString();
        },

        destroy() {
            this.instance?.destroy();
        },
    }));

    /**
     * Drag-to-reorder that reports the new order back to a Livewire method.
     */
    window.Alpine.data('sortableList', (config = {}) => ({
        instance: null,

        init() {
            const draggable = config.draggable ?? '[data-sortable-item]';

            this.instance = Sortable.create(this.$el, {
                handle: config.handle ?? '[data-sortable-handle]',
                draggable,
                animation: 150,
                ghostClass: 'opacity-40',
                onEnd: () => {
                    const order = Array.from(this.$el.querySelectorAll(draggable)).map(
                        (element) => element.dataset.sortableId
                    );

                    if (config.method) {
                        this.$wire.call(config.method, order);
                    }
                },
            });
        },

        destroy() {
            this.instance?.destroy();
        },
    }));
});
