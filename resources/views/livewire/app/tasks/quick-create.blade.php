{{--
    Quick create.

    Mounted once by the shell and inert until somebody presses `C`, so the closed state is a
    single empty element and zero queries. Alpine puts the sheet on screen in the same frame
    as the keystroke; the server fills in the options behind it.
--}}
<div>
    <x-ui.modal wire:model="open" size="md" :title="__('New task')"
                :description="__('Enough to get it written down. Everything else can wait for the task itself.')">

        @if ($open)
            @if ($this->projects->isEmpty())
                <x-ui.empty-state compact icon="icon.folder"
                                  :title="__('No project to put it in')"
                                  :description="__('You need a project you can add tasks to before you can create one.')" />
            @else
                @if ($this->canUseAi())
                    <x-ui.tabs variant="pill" class="mb-3">
                        <x-ui.tab variant="pill" :active="$mode === 'form'" wire:click="$set('mode', 'form')">
                            {{ __('Fields') }}
                        </x-ui.tab>
                        <x-ui.tab variant="pill" :active="$mode === 'describe'" wire:click="$set('mode', 'describe')"
                                  icon="icon.sparkles">
                            {{ __('Describe it') }}
                        </x-ui.tab>
                    </x-ui.tabs>
                @endif

                @if ($error)
                    <p class="mb-3 rounded-md border border-critical-500/40 bg-critical-50 px-2.5 py-1.5 text-xs
                              text-critical-700 dark:bg-critical-950/60 dark:text-critical-100" role="alert">
                        {{ $error }}
                    </p>
                @endif

                @if ($mode === 'describe' && $this->canUseAi())
                    <form wire:submit="describe" class="space-y-3">
                        <x-ui.field :label="__('What needs doing?')"
                                    :hint="__('Planvio AI reads it, drafts the task and shows you what it changed before anything is final.')">
                            <x-ui.textarea wire:model="sentence" rows="3" maxlength="2000"
                                           :placeholder="__('e.g. Draft the launch email for the pricing page, due Friday, assign to Mei')">{{ $sentence }}</x-ui.textarea>
                        </x-ui.field>

                        <div class="flex items-center justify-end gap-2">
                            <x-ui.button variant="secondary" size="md" wire:click="close" type="button">
                                {{ __('Cancel') }}
                            </x-ui.button>
                            <x-ui.button variant="primary" size="md" type="submit" icon="icon.sparkles"
                                         wire:target="describe">
                                {{ __('Hand it to AI') }}
                            </x-ui.button>
                        </div>
                    </form>
                @else
                    <form wire:submit="create" class="space-y-3">
                        <x-ui.field :label="__('Title')" required for="quick-title">
                            <x-ui.input id="quick-title" wire:model="title" :value="$title" size="lg" maxlength="255" autofocus
                                        :placeholder="__('What needs doing?')" />
                        </x-ui.field>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <x-ui.field :label="__('Project')" for="quick-project">
                                <x-ui.select id="quick-project" wire:model.live="projectId">
                                    @foreach ($this->projects as $option)
                                        <option value="{{ $option->getKey() }}" @selected($projectId === (int) $option->getKey())>{{ $option->name }} ({{ $option->key }})</option>
                                    @endforeach
                                </x-ui.select>
                            </x-ui.field>

                            <x-ui.field :label="__('Status')" for="quick-status">
                                <x-ui.select id="quick-status" wire:model="statusId">
                                    @foreach ($this->statuses as $status)
                                        <option value="{{ $status->getKey() }}" @selected($statusId === (int) $status->getKey())>{{ $status->name }}</option>
                                    @endforeach
                                </x-ui.select>
                            </x-ui.field>

                            <x-ui.field :label="__('Assignee')" for="quick-assignee">
                                <x-ui.select id="quick-assignee" wire:model="assigneeId">
                                    <option value="">{{ __('Unassigned') }}</option>
                                    @foreach ($this->members as $member)
                                        <option value="{{ $member->getKey() }}" @selected($assigneeId === (int) $member->getKey())>{{ $member->name }}</option>
                                    @endforeach
                                </x-ui.select>
                            </x-ui.field>

                            <x-ui.field :label="__('Priority')" for="quick-priority">
                                <x-ui.select id="quick-priority" wire:model="priority">
                                    @foreach (array_reverse(\App\Enums\Priority::cases()) as $case)
                                        <option value="{{ $case->value }}" @selected($priority === $case->value)>{{ $case->label() }}</option>
                                    @endforeach
                                </x-ui.select>
                            </x-ui.field>

                            <x-ui.field :label="__('Due date')" for="quick-due" class="sm:col-span-2">
                                <x-ui.input id="quick-due" type="date" wire:model="dueDate" :value="$dueDate" />
                            </x-ui.field>
                        </div>

                        <div class="flex flex-wrap items-center justify-end gap-2 pt-1">
                            <x-ui.button variant="ghost" size="md" type="button" wire:click="close">
                                {{ __('Cancel') }}
                            </x-ui.button>
                            <x-ui.button variant="secondary" size="md" type="button" wire:click="create(true)"
                                         wire:target="create(true)">
                                {{ __('Create and add another') }}
                            </x-ui.button>
                            <x-ui.button variant="primary" size="md" type="submit" wire:target="create">
                                {{ __('Create task') }}
                            </x-ui.button>
                        </div>
                    </form>
                @endif
            @endif
        @endif
    </x-ui.modal>
</div>
