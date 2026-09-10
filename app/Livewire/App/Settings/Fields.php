<?php

declare(strict_types=1);

namespace App\Livewire\App\Settings;

use App\Actions\CustomFields\CreateCustomField;
use App\Actions\CustomFields\DeleteCustomField;
use App\Actions\CustomFields\UpdateCustomField;
use App\Enums\CustomFieldType;
use App\Exceptions\DomainException;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Workspace-wide custom fields: the extra attributes tasks and projects carry beyond the
 * ones Planvio ships with.
 *
 * Only workspace-level fields are edited here (`project_id` null). A field scoped to a
 * single project belongs to that project's own settings, where the person changing it can
 * see what it is attached to.
 *
 * Deactivating is offered next to deleting, and deliberately first: switching a field off
 * hides it from every form while leaving the answers people already gave intact. Deleting
 * takes the answers with it, and says how many.
 */
final class Fields extends Component
{
    public Workspace $workspace;

    public string $entity = CustomField::ENTITY_TASK;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $key = '';

    public string $type = 'text';

    /** One choice per line — the honest editor for a short list. */
    public string $options = '';

    public bool $isRequired = false;

    public bool $isActive = true;

    public function mount(Workspace $workspace): void
    {
        // No workspace argument: CustomFieldPolicy narrows by *project*, and passing the
        // tenant where a project is expected would be a type error rather than a check.
        // The bound workspace is what the policy falls back to, which is this one.
        $this->authorize('viewAny', [CustomField::class]);

        $this->workspace = $workspace;
    }

    /**
     * @return Collection<int, CustomField>
     */
    #[Computed]
    public function fields(): Collection
    {
        return CustomField::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->whereNull('project_id')
            ->where('entity', $this->entity)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * How many answers each field is holding, so "delete" can say what it costs.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function answerCounts(): array
    {
        $ids = $this->fields->modelKeys();

        if ($ids === []) {
            return [];
        }

        return CustomFieldValue::query()
            ->whereIn('custom_field_id', $ids)
            ->selectRaw('custom_field_id, count(*) as aggregate')
            ->groupBy('custom_field_id')
            ->pluck('aggregate', 'custom_field_id')
            ->map(intval(...))
            ->all();
    }

    public function setEntity(string $entity): void
    {
        $this->entity = $entity === CustomField::ENTITY_PROJECT
            ? CustomField::ENTITY_PROJECT
            : CustomField::ENTITY_TASK;

        unset($this->fields, $this->answerCounts);
    }

    public function startCreate(): void
    {
        $this->authorize('create', [CustomField::class, null]);

        $this->editingId = null;
        $this->name = '';
        $this->key = '';
        $this->type = CustomFieldType::Text->value;
        $this->options = '';
        $this->isRequired = false;
        $this->isActive = true;
        $this->showForm = true;

        $this->resetValidation();
    }

    public function startEdit(int $fieldId): void
    {
        $field = $this->field($fieldId);

        if ($field === null) {
            return;
        }

        $this->authorize('update', $field);

        $this->editingId = (int) $field->getKey();
        $this->name = (string) $field->name;
        $this->key = (string) $field->key;
        $this->type = ($field->type ?? CustomFieldType::Text)->value;
        $this->options = implode("\n", array_map(strval(...), (array) ($field->options ?? [])));
        $this->isRequired = (bool) $field->is_required;
        $this->isActive = (bool) $field->is_active;
        $this->showForm = true;

        $this->resetValidation();
    }

    public function save(CreateCustomField $createField, UpdateCustomField $updateField): void
    {
        $field = $this->editingId === null ? null : $this->field($this->editingId);

        if ($field === null) {
            $this->authorize('create', [CustomField::class, null]);
        } else {
            $this->authorize('update', $field);
        }

        $data = $this->validate([
            'name' => ['required', 'string', 'min:1', 'max:80'],
            'key' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'type' => ['required', Rule::in(array_column(CustomFieldType::cases(), 'value'))],
            'options' => ['nullable', 'string', 'max:4000'],
            'isRequired' => ['boolean'],
            'isActive' => ['boolean'],
        ], attributes: ['key' => __('key')]);

        $type = CustomFieldType::from($data['type']);
        $choices = $this->parsedOptions();

        if ($type->hasOptions() && $choices === []) {
            $this->addError('options', __('A choice list needs at least one option.'));

            return;
        }

        try {
            if ($field === null) {
                $createField(
                    workspace: $this->workspace,
                    actor: $this->actor(),
                    name: $data['name'],
                    type: $type,
                    key: $data['key'] === '' ? null : $data['key'],
                    project: null,
                    entity: $this->entity,
                    options: $type->hasOptions() ? $choices : null,
                    isRequired: (bool) $data['isRequired'],
                );
            } else {
                $updateField(
                    field: $field,
                    actor: $this->actor(),
                    name: $data['name'],
                    type: $type,
                    options: $type->hasOptions() ? $choices : null,
                    isRequired: (bool) $data['isRequired'],
                    isActive: (bool) $data['isActive'],
                    key: $data['key'] === '' ? null : $data['key'],
                );
            }
        } catch (DomainException $failure) {
            $this->addError('name', $failure->userMessage());

            return;
        }

        $this->showForm = false;
        $this->editingId = null;

        unset($this->fields, $this->answerCounts);

        $this->dispatch('planvio-notify', type: 'success', message: __('Custom fields updated.'));
    }

    public function toggleActive(int $fieldId, UpdateCustomField $updateField): void
    {
        $field = $this->field($fieldId);

        if ($field === null) {
            return;
        }

        $this->authorize('update', $field);

        $updateField(
            field: $field,
            actor: $this->actor(),
            name: (string) $field->name,
            type: $field->type,
            options: $field->options,
            isRequired: (bool) $field->is_required,
            isActive: ! $field->is_active,
            key: (string) $field->key,
        );

        unset($this->fields);

        $this->dispatch('planvio-notify', type: 'success', message: $field->is_active
            ? __('“:field” is switched off. Existing answers are kept.', ['field' => $field->name])
            : __('“:field” is switched on again.', ['field' => $field->name]));
    }

    public function deleteField(int $fieldId, DeleteCustomField $deleteField): void
    {
        $field = $this->field($fieldId);

        if ($field === null) {
            return;
        }

        $this->authorize('delete', $field);

        $deleteField($field, $this->actor());

        unset($this->fields, $this->answerCounts);

        $this->dispatch('planvio-notify', type: 'success', message: __('“:field” was deleted.', [
            'field' => $field->name,
        ]));
    }

    public function render(): View
    {
        return view('livewire.app.settings.fields');
    }

    /**
     * @return list<string>
     */
    private function parsedOptions(): array
    {
        $lines = preg_split('/\R/', $this->options) ?: [];

        return array_values(array_unique(array_filter(
            array_map(static fn (string $line): string => trim($line), $lines),
            static fn (string $line): bool => $line !== '',
        )));
    }

    private function field(int $fieldId): ?CustomField
    {
        return CustomField::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->whereNull('project_id')
            ->whereKey($fieldId)
            ->first();
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
