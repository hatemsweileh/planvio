/**
 * Planvio application JavaScript.
 *
 * Alpine ships inside Livewire's bundle, so we never import or start Alpine here —
 * doing so would register two instances. Everything hooks the `alpine:init` event.
 */

import Sortable from 'sortablejs';
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Placeholder from '@tiptap/extension-placeholder';
import Link from '@tiptap/extension-link';
import TaskList from '@tiptap/extension-task-list';
import TaskItem from '@tiptap/extension-task-item';

/* -------------------------------------------------------------------------
 * Theme. Applied pre-paint by an inline script in the layout head to avoid a
 * flash; this module only handles switching and cross-tab sync afterwards.
 * ---------------------------------------------------------------------- */
const THEME_KEY = 'planvio.theme';

function resolveTheme(preference) {
    if (preference === 'dark' || preference === 'light') return preference;
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function applyTheme(preference) {
    const resolved = resolveTheme(preference);
    document.documentElement.classList.toggle('dark', resolved === 'dark');
    document.documentElement.style.colorScheme = resolved;
}

window.Planvio = window.Planvio || {};

window.Planvio.setTheme = (preference) => {
    try {
        localStorage.setItem(THEME_KEY, preference);
    } catch (e) {
        /* private mode: fall through, the choice just will not persist */
    }
    applyTheme(preference);
    window.dispatchEvent(new CustomEvent('planvio:theme-changed', { detail: { preference } }));
};

/**
 * Keep a chart's floating readout inside the plot it belongs to.
 *
 * The readout is centred on the mark with `-translate-x-1/2`, so a point at either end of
 * the axis put half of it outside the chart — and for the last point of a full-width chart,
 * outside the window, which is the one place it cannot be read. This clamps the centre to
 * half the readout's own width from each edge.
 *
 * It stops tracking the mark exactly at the extremes, which is the right trade: a readout
 * slightly off its point is still legible, one off the screen is not. Returns the requested
 * position unchanged while the element is still hidden and has no width to centre.
 */
window.Planvio.clampTip = (el, x) => {
    const width = el?.offsetWidth ?? 0;
    const limit = el?.parentElement?.clientWidth ?? 0;

    if (!width || !limit) return x;

    const half = width / 2;
    const min = half + 2;

    return Math.min(Math.max(x, min), Math.max(min, limit - half - 2));
};

window.Planvio.getTheme = () => {
    try {
        return localStorage.getItem(THEME_KEY) || 'system';
    } catch (e) {
        return 'system';
    }
};

window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    if (window.Planvio.getTheme() === 'system') applyTheme('system');
});

window.addEventListener('storage', (event) => {
    if (event.key === THEME_KEY) applyTheme(event.newValue || 'system');
});

/* -------------------------------------------------------------------------
 * Alpine components
 * ---------------------------------------------------------------------- */
document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    Alpine.data('themeToggle', () => ({
        preference: window.Planvio.getTheme(),
        set(value) {
            this.preference = value;
            window.Planvio.setTheme(value);
        },
    }));

    /**
     * Kanban column. Each column is its own Sortable instance sharing a group,
     * so cards move between columns. On drop we emit a single Livewire call with
     * the destination column and the neighbouring card ids; the server computes
     * the fractional `position` rather than trusting a client-sent index.
     */
    Alpine.data('kanbanColumn', (statusId) => ({
        sortable: null,
        init() {
            this.sortable = Sortable.create(this.$refs.list, {
                group: 'planvio-board',
                animation: 150,
                easing: 'cubic-bezier(0.32, 0.72, 0, 1)',
                ghostClass: 'sortable-ghost',
                dragClass: 'sortable-drag',
                chosenClass: 'sortable-chosen',
                handle: '[data-drag-handle]',
                filter: '[data-no-drag]',
                delay: 40,
                delayOnTouchOnly: true,
                touchStartThreshold: 6,
                onEnd: (event) => {
                    const item = event.item;
                    const list = event.to;
                    const cards = Array.from(list.querySelectorAll('[data-task-id]'));
                    const index = cards.indexOf(item);

                    this.$wire.call(
                        'moveTask',
                        Number(item.dataset.taskId),
                        Number(list.dataset.statusId),
                        index > 0 ? Number(cards[index - 1].dataset.taskId) : null,
                        index < cards.length - 1 ? Number(cards[index + 1].dataset.taskId) : null,
                    );
                },
            });
        },
        destroy() {
            this.sortable?.destroy();
        },
    }));

    /** Generic vertical reorder list (checklists, subtasks, statuses, wiki tree). */
    Alpine.data('sortableList', (method = 'reorder', group = null) => ({
        sortable: null,
        init() {
            this.sortable = Sortable.create(this.$el, {
                group: group || undefined,
                animation: 140,
                handle: '[data-drag-handle]',
                ghostClass: 'sortable-ghost',
                onEnd: () => {
                    const ids = Array.from(this.$el.querySelectorAll('[data-sort-id]')).map((el) =>
                        Number(el.dataset.sortId),
                    );
                    this.$wire.call(method, ids);
                },
            });
        },
        destroy() {
            this.sortable?.destroy();
        },
    }));

    /**
     * Rich text editor. Emits HTML which the server re-sanitises with HTMLPurifier —
     * client-side output is never trusted.
     */
    Alpine.data('richEditor', (initial = '', placeholder = '', readOnly = false) => ({
        editor: null,
        html: initial,
        init() {
            this.editor = new Editor({
                element: this.$refs.editor,
                editable: !readOnly,
                extensions: [
                    StarterKit.configure({
                        heading: { levels: [1, 2, 3] },
                        codeBlock: { HTMLAttributes: { class: 'not-prose' } },
                    }),
                    Placeholder.configure({ placeholder }),
                    Link.configure({
                        openOnClick: false,
                        autolink: true,
                        protocols: ['http', 'https', 'mailto'],
                        HTMLAttributes: { rel: 'noopener noreferrer nofollow', target: '_blank' },
                    }),
                    TaskList,
                    TaskItem.configure({ nested: true }),
                ],
                content: initial,
                editorProps: {
                    attributes: {
                        class: 'prose-planvio focus:outline-none min-h-24 px-3 py-2',
                        /*
                         * The page's direction is the reader's; what is typed in here is the
                         * writer's, and the two are not the same fact. Without this an
                         * English wiki page is composed right-to-left inside an Arabic
                         * interface and then read left-to-right afterwards, because the
                         * rendered page already carries dir="auto" — the editor and the
                         * document it produces disagreed about the same text.
                         */
                        dir: 'auto',
                    },
                },
                onUpdate: ({ editor }) => {
                    this.html = editor.isEmpty ? '' : editor.getHTML();
                    this.$dispatch('editor-input', { html: this.html });
                },
            });

            // Livewire may re-render around us; keep the document in sync without
            // stomping on the user's cursor while they are typing.
            this.$watch('html', (value) => {
                if (this.editor && !this.editor.isFocused && value !== this.editor.getHTML()) {
                    this.editor.commands.setContent(value || '', false);
                }
            });
        },
        destroy() {
            this.editor?.destroy();
        },
        run(command, ...args) {
            this.editor?.chain().focus()[command](...args).run();
        },
        isActive(name, attrs = {}) {
            return this.editor?.isActive(name, attrs) ?? false;
        },
    }));

    /**
     * Global keyboard shortcuts. Deliberately inert while the user is typing so
     * single-key shortcuts never hijack a text field.
     */
    Alpine.data('shortcuts', () => ({
        init() {
            this._handler = (event) => {
                const el = event.target;
                const typing =
                    el.isContentEditable ||
                    ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName);

                if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                    event.preventDefault();
                    this.$dispatch('open-command-palette');
                    return;
                }

                if (typing || event.metaKey || event.ctrlKey || event.altKey) return;

                if (event.key === '/') {
                    event.preventDefault();
                    this.$dispatch('open-command-palette', { mode: 'search' });
                } else if (event.key.toLowerCase() === 'c') {
                    event.preventDefault();
                    this.$dispatch('open-quick-create');
                } else if (event.key.toLowerCase() === 'a') {
                    event.preventDefault();
                    this.$dispatch('open-ai-panel');
                } else if (event.key === '?') {
                    event.preventDefault();
                    this.$dispatch('open-shortcuts-help');
                }
            };
            window.addEventListener('keydown', this._handler);
        },
        destroy() {
            window.removeEventListener('keydown', this._handler);
        },
    }));

    /**
     * Anchored overlay: a panel that is tied to a trigger and never leaves the window.
     *
     * Two separate problems are solved here, and both have to be, because fixing one
     * without the other still leaves a menu you cannot read.
     *
     * **Clipping.** An `absolute` panel is painted inside its nearest positioned ancestor,
     * so any scroller between it and the page — a sidebar, a board column, the task
     * drawer, a table with `overflow-x-auto` — cuts it off. The panel is therefore put in
     * the **top layer** with the Popover API, which paints above the whole document and
     * escapes every `overflow` and `z-index` on the way up. Crucially the element does not
     * move in the DOM to get there, so Livewire's diffing is untouched — a portal into
     * `<body>` would break it. Where `popover` is unsupported the panel falls back to
     * `position: fixed`, which is clipped only by a transformed ancestor and is a great
     * deal better than `absolute`.
     *
     * **Overflow.** Being in the top layer does not stop a panel running off the edge of
     * the screen. So the position is computed against the viewport: the panel flips to the
     * opposite side when its preferred side has no room, slides along the cross axis to
     * stay inside, and is finally capped in both dimensions so a long menu scrolls
     * internally instead of extending past the bottom of the window.
     *
     * Placements are logical — `start` and `end` mean *before* and *after* along the
     * reading direction, so a menu opens the correct way in Arabic without a second code
     * path.
     */
    const OVERLAY_GAP = 6;      // distance between trigger and panel
    const OVERLAY_INSET = 8;    // smallest distance the panel may sit from a window edge
    const OVERLAY_MIN = 120;    // never squeeze a panel smaller than this before scrolling

    const supportsPopover = typeof HTMLElement !== 'undefined'
        && Object.prototype.hasOwnProperty.call(HTMLElement.prototype, 'popover');

    /** Physical side for a logical one, in the direction the document is written. */
    function resolveSide(side, rtl) {
        if (side === 'start') return rtl ? 'right' : 'left';
        if (side === 'end') return rtl ? 'left' : 'right';
        return side;
    }

    function placeFloating(panel, trigger, { placement = 'bottom', align = 'start' } = {}) {
        if (!panel || !trigger) return;

        const rtl = getComputedStyle(document.documentElement).direction === 'rtl';

        // Measure unconstrained, or last call's cap becomes this call's ceiling and the
        // panel ratchets smaller every time it opens.
        panel.style.maxWidth = '';
        panel.style.maxHeight = '';

        const vw = document.documentElement.clientWidth;
        const vh = document.documentElement.clientHeight;
        const t = trigger.getBoundingClientRect();

        let side = resolveSide(placement, rtl);
        const vertical = side === 'top' || side === 'bottom';

        const room = {
            top: t.top - OVERLAY_INSET,
            bottom: vh - t.bottom - OVERLAY_INSET,
            left: t.left - OVERLAY_INSET,
            right: vw - t.right - OVERLAY_INSET,
        };
        const flipped = { top: 'bottom', bottom: 'top', left: 'right', right: 'left' };

        let box = panel.getBoundingClientRect();

        // A panel revealed this tick may not be laid out yet, and a zero-width box would
        // be "aligned" by subtracting nothing — parking a 256px menu with its left edge on
        // the trigger's right edge, well outside the window. Better to place nothing than
        // to place it wrongly: the next-frame call and the ResizeObserver both re-run this,
        // and the panel is still at opacity 0 until they do.
        if (box.width === 0 && box.height === 0) {
            return;
        }

        const wanted = (vertical ? box.height : box.width) + OVERLAY_GAP;

        // Flip only when the other side is genuinely roomier. Flipping into an equally
        // cramped side just moves the problem and makes the panel jump about.
        if (room[side] < wanted && room[flipped[side]] > room[side]) {
            side = flipped[side];
        }

        const acrossAxis = side === 'top' || side === 'bottom';
        panel.style.maxWidth = Math.max(OVERLAY_MIN, (acrossAxis ? vw - OVERLAY_INSET * 2 : room[side] - OVERLAY_GAP)) + 'px';
        panel.style.maxHeight = Math.max(OVERLAY_MIN, (acrossAxis ? room[side] - OVERLAY_GAP : vh - OVERLAY_INSET * 2)) + 'px';

        box = panel.getBoundingClientRect();
        const w = box.width;
        const h = box.height;

        let x;
        let y;

        if (acrossAxis) {
            y = side === 'top' ? t.top - h - OVERLAY_GAP : t.bottom + OVERLAY_GAP;
            const at = align === 'center' ? 'center' : resolveSide(align, rtl);
            if (at === 'center') x = t.left + t.width / 2 - w / 2;
            else if (at === 'right') x = t.right - w;
            else x = t.left;
        } else {
            x = side === 'left' ? t.left - w - OVERLAY_GAP : t.right + OVERLAY_GAP;
            const at = align === 'center' ? 'center' : align;
            if (at === 'center') y = t.top + t.height / 2 - h / 2;
            else if (at === 'end') y = t.bottom - h;
            else y = t.top;
        }

        // Slide back inside. Applied last so it wins over the alignment above: being
        // readable matters more than being flush with the trigger.
        x = Math.min(Math.max(OVERLAY_INSET, x), Math.max(OVERLAY_INSET, vw - w - OVERLAY_INSET));
        y = Math.min(Math.max(OVERLAY_INSET, y), Math.max(OVERLAY_INSET, vh - h - OVERLAY_INSET));

        // The UA stylesheet centres a popover with `inset: 0; margin: auto`; both have to
        // go or the offsets below are measured from the wrong box.
        panel.style.position = 'fixed';
        panel.style.inset = 'auto';
        panel.style.margin = '0';
        panel.style.left = Math.round(x) + 'px';
        panel.style.top = Math.round(y) + 'px';

        // Grow from the corner nearest the trigger, so the panel reads as belonging to it.
        panel.style.transformOrigin = acrossAxis
            ? `${x + w / 2 < t.left + t.width / 2 ? 'right' : 'left'} ${side === 'top' ? 'bottom' : 'top'}`
            : `${side === 'left' ? 'right' : 'left'} ${y + h / 2 < t.top + t.height / 2 ? 'bottom' : 'top'}`;
    }

    Alpine.data('anchored', (options = {}) => ({
        open: false,
        _release: null,

        /*
         | `open` is the single source of truth and a watcher drives the DOM from it,
         | rather than `show()`/`hide()` doing the work directly. That is deliberate:
         | menu items across the product already close their menu by assigning
         | `open = false`, and a design where only the methods worked would leave those
         | panels visible in the top layer with the component believing them shut.
         */
        init() {
            this.$watch('open', (value) => (value ? this._mount() : this._unmount()));
        },

        panel() {
            return this.$refs.panel;
        },

        anchor() {
            return this.$refs.trigger ?? this.$el;
        },

        reposition() {
            if (this.open) placeFloating(this.panel(), this.anchor(), options);
        },

        _mount() {
            const panel = this.panel();
            if (!panel) return;

            if (supportsPopover && panel.hasAttribute('popover')) {
                try {
                    panel.showPopover();
                } catch (e) {
                    /* already open, or detached mid-frame */
                }
            } else {
                panel.setAttribute('data-open', '');
            }

            // Twice, and both are needed.
            //
            // Synchronously, so the panel is only ever painted where it belongs and there
            // is no first-frame flash in the wrong corner. Then again on the next frame,
            // because this first measurement is taken in the same task that revealed the
            // panel: the trigger's box can still settle by a few pixels after that, and
            // the placement would keep the stale value for as long as the panel stayed
            // open. Measuring layout in the tick that changed it is exactly when a stale
            // value is returned.
            this.reposition();
            requestAnimationFrame(() => this.reposition());

            const onMove = () => this.reposition();
            // Capture, because the scroller that moves the trigger is usually an inner
            // one and scroll does not bubble.
            window.addEventListener('scroll', onMove, true);
            window.addEventListener('resize', onMove);

            const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(onMove);
            observer?.observe(panel);

            this._release = () => {
                window.removeEventListener('scroll', onMove, true);
                window.removeEventListener('resize', onMove);
                observer?.disconnect();
            };
        },

        _unmount() {
            const panel = this.panel();
            if (panel) {
                if (supportsPopover && panel.hasAttribute('popover')) {
                    try {
                        panel.hidePopover();
                    } catch (e) {
                        /* already closed */
                    }
                } else {
                    panel.removeAttribute('data-open');
                }
            }

            this._release?.();
            this._release = null;
        },

        show() {
            this.open = true;
        },

        hide() {
            this.open = false;
        },

        toggle() {
            this.open = !this.open;
        },

        /**
         * Light dismiss. `popover="manual"` is used rather than `auto` so that opening one
         * menu cannot silently close a dialog that happens to be a popover too; the cost
         * is owning this, which is a few lines.
         */
        onOutside(event) {
            if (!this.open) return;
            if (this.$el.contains(event.target)) return;
            if (this.panel()?.contains(event.target)) return;
            this.hide();
        },

        destroy() {
            this._release?.();
        },
    }));

    /** Roving-focus list used by the command palette and mention menus. */
    Alpine.data('optionList', () => ({
        active: 0,
        count: 0,
        move(delta) {
            if (this.count === 0) return;
            this.active = (this.active + delta + this.count) % this.count;
            this.$nextTick(() => {
                this.$refs.list
                    ?.querySelector(`[data-option="${this.active}"]`)
                    ?.scrollIntoView({ block: 'nearest' });
            });
        },
    }));

    /** Copy-to-clipboard with a transient confirmation. */
    Alpine.data('copyable', (text) => ({
        copied: false,
        async copy() {
            try {
                await navigator.clipboard.writeText(text);
                this.copied = true;
                setTimeout(() => (this.copied = false), 1600);
            } catch (e) {
                /* clipboard unavailable over plain http: silently no-op */
            }
        },
    }));

    /** Live-updating relative timestamp, ticking no faster than once a minute. */
    Alpine.data('relativeTime', (iso) => ({
        label: '',
        init() {
            this.render();
            this._timer = setInterval(() => this.render(), 60000);
        },
        destroy() {
            clearInterval(this._timer);
        },
        render() {
            const then = new Date(iso);
            const seconds = Math.round((Date.now() - then.getTime()) / 1000);
            /*
             * `numberingSystem: 'latn'` because Planvio writes figures in Latin digits in
             * every language (App\Support\Formats, docs/LOCALISATION.md §8). Left to itself,
             * ICU answers an `ar` locale in Arabic-Indic — so this one label would read
             * "قبل ٤ دقائق" beside a duration and a task key that are still 4 and WEB-142.
             * The server-side rule is asserted by ArabicFormattingTest; this is the one
             * formatter that runs in the browser, where that test cannot see it.
             */
            const rtf = new Intl.RelativeTimeFormat(document.documentElement.lang || 'en', {
                numeric: 'auto',
                numberingSystem: 'latn',
            });
            const units = [
                ['year', 31536000],
                ['month', 2592000],
                ['week', 604800],
                ['day', 86400],
                ['hour', 3600],
                ['minute', 60],
            ];
            for (const [unit, secs] of units) {
                if (Math.abs(seconds) >= secs) {
                    this.label = rtf.format(-Math.round(seconds / secs), unit);
                    return;
                }
            }
            this.label = rtf.format(0, 'second');
        },
    }));
});

/* -------------------------------------------------------------------------
 * Livewire integration
 * ---------------------------------------------------------------------- */
document.addEventListener('livewire:init', () => {
    // Server-driven toasts.
    window.Livewire.on('planvio-notify', (payload) => {
        window.dispatchEvent(new CustomEvent('planvio:toast', { detail: payload[0] ?? payload }));
    });

    // A slow AI run should not look like a frozen page.
    window.Livewire.hook('request', ({ respond }) => {
        const timer = setTimeout(() => document.body.setAttribute('data-loading', 'true'), 400);
        respond(() => {
            clearTimeout(timer);
            document.body.removeAttribute('data-loading');
        });
    });
});

export { Sortable, Editor };
