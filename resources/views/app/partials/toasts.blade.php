{{--
    Toast host. Listens for the `planvio:toast` window event dispatched by
    resources/js/app.js when the server emits `planvio-notify`, so any Livewire
    component can raise one with:

        $this->dispatch('planvio-notify', type: 'success', message: __('Task created'));

    Deliberately bottom-right on desktop and top on mobile, where the bottom edge is
    occupied by the browser chrome and the thumb.
--}}
<div x-data="{
        toasts: [],
        add(detail) {
            const id = Date.now() + Math.random();
            this.toasts.push({
                id,
                type: detail.type || 'info',
                message: detail.message || '',
                title: detail.title || null,
            });
            const ttl = detail.type === 'error' ? 8000 : 4500;
            setTimeout(() => this.remove(id), ttl);
        },
        remove(id) { this.toasts = this.toasts.filter(t => t.id !== id) },
     }"
     x-on:planvio:toast.window="add($event.detail || {})"
     class="pointer-events-none fixed inset-x-0 top-3 z-[60] flex flex-col items-center gap-2 px-3
            sm:inset-x-auto sm:bottom-4 sm:end-4 sm:top-auto sm:items-end sm:px-0"
     role="status"
     aria-live="polite">

    <template x-for="toast in toasts" :key="toast.id">
        <div x-transition:enter="transition ease-[cubic-bezier(0.32,0.72,0,1)] duration-200"
             x-transition:enter-start="opacity-0 -translate-y-2 sm:translate-y-2 sm:translate-x-0"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-97"
             class="pointer-events-auto flex w-full max-w-sm items-start gap-2.5 rounded-lg border
                    border-[var(--line-subtle)] bg-[var(--surface-raised)] p-3 shadow-overlay">

            <span class="mt-px grid size-4.5 shrink-0 place-items-center rounded-full"
                  :class="{
                      'bg-positive-100 text-positive-700 dark:bg-positive-500/20 dark:text-positive-100': toast.type === 'success',
                      'bg-critical-100 text-critical-700 dark:bg-critical-500/20 dark:text-critical-100': toast.type === 'error',
                      'bg-caution-100 text-caution-700 dark:bg-caution-500/20 dark:text-caution-100': toast.type === 'warning',
                      'bg-brand-100 text-brand-700 dark:bg-brand-500/20 dark:text-brand-200': toast.type === 'info',
                  }">
                <svg class="size-3" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                     stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path x-show="toast.type === 'success'" d="m4 10.5 4 4 8-9"/>
                    <path x-show="toast.type === 'error'" d="M5 5l10 10M15 5 5 15"/>
                    <path x-show="toast.type === 'warning'" d="M10 5v6M10 14.5h.01"/>
                    <path x-show="toast.type === 'info'" d="M10 9v6M10 5.5h.01"/>
                </svg>
            </span>

            <div class="min-w-0 flex-1">
                <p x-show="toast.title" x-text="toast.title"
                   class="text-sm font-medium text-[var(--text-strong)]"></p>
                <p x-text="toast.message"
                   class="text-sm text-[var(--text-DEFAULT)]"
                   :class="toast.title && 'mt-0.5 text-xs text-[var(--text-muted)]'"></p>
            </div>

            <button type="button" x-on:click="remove(toast.id)"
                    class="-m-1 grid size-6 shrink-0 place-items-center rounded text-[var(--text-subtle)]
                           transition-colors hover:bg-[var(--surface-hover)] hover:text-[var(--text-DEFAULT)]"
                    aria-label="{{ __('Dismiss') }}">
                <svg class="size-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path d="m5 5 10 10M15 5 5 15" stroke-linecap="round"/>
                </svg>
            </button>
        </div>
    </template>
</div>
