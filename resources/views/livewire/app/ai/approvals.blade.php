{{--
    The approvals queue.

    Everything the agent has stopped short of doing, waiting on somebody who can say yes.
    Nothing here has happened; every card is a proposal with its exact arguments attached,
    and closing this tab changes nothing except how long the run sits parked.

    It polls slowly and only while on screen: new requests arrive from a queue worker rather
    than from anything this page did, and a request that has outlived its expiry is refused
    at execution time whatever this page last drew.
--}}
<div class="page page-prose py-5" wire:poll.visible.15s="refreshQueue">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h2 class="text-sm font-semibold tracking-tight text-[var(--text-strong)]">
                {{ __('Waiting for a decision') }}
            </h2>
            <p class="mt-1 max-w-xl text-xs leading-relaxed text-[var(--text-muted)]">
                {{ __('The assistant proposes; you decide. Each card shows the exact call it would make, what that call would affect, and who it would act for. Nothing on this page has happened yet.') }}
            </p>
        </div>

        @if ($this->pendingApprovals->isNotEmpty())
            <x-ui.badge color="amber" size="md">
                {{ trans_choice('{1}:count waiting|[2,*]:count waiting', $this->pendingApprovals->count(), [
                    'count' => $this->pendingApprovals->count(),
                ]) }}
            </x-ui.badge>
        @endif
    </div>

    <div class="mt-4 space-y-4">
        @forelse ($this->pendingApprovals as $toolRun)
            @include('livewire.app.ai._approval-card', [
                'toolRun' => $toolRun,
                'indent' => '',
                'showObjective' => true,
            ])
        @empty
            <div class="rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-panel)] shadow-panel">
                <x-ui.empty-state icon="icon.check-circle"
                                  :title="__('Nothing is waiting on you')"
                                  :description="__('When the assistant reaches an action it may not take on its own, it stops here and asks. An empty queue means every run so far finished inside what it was allowed to do.')" />
            </div>
        @endforelse
    </div>
</div>
