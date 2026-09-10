<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectTemplates;

use App\Enums\ProjectType;
use App\Filament\Resources\ProjectTemplates\Pages\CreateProjectTemplate;
use App\Filament\Resources\ProjectTemplates\Pages\EditProjectTemplate;
use App\Filament\Resources\ProjectTemplates\Pages\ListProjectTemplates;
use App\Filament\Support\NavigationGroup;
use App\Filament\Support\PanelColor;
use App\Filament\Support\PlatformResource;
use App\Models\ProjectTemplate;
use App\Models\Workspace;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use JsonException;
use UnitEnum;

/**
 * The blueprints behind "create a project from a template".
 *
 * A template with no workspace is a system template, offered in every workspace on the
 * installation — which is why this resource is not tenant-scoped, and why the workspace field
 * is optional. `App\Actions\Projects\CreateProjectFromTemplate` reads `definition` and creates
 * the statuses, milestones, tasks, tags and saved views it describes.
 *
 * # The definition is edited as JSON, deliberately
 *
 * A form builder over this structure would be a second, weaker copy of the project editor, and
 * it would have to be kept in step with `CreateProjectFromTemplate` for ever. The people editing
 * a system template are running the installation; JSON, validated on save, is the honest tool.
 * A definition that is not valid JSON is refused here rather than discovered by whoever creates
 * the next project.
 *
 * @extends PlatformResource<ProjectTemplate>
 */
final class ProjectTemplateResource extends PlatformResource
{
    protected static ?string $model = ProjectTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Platform;

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('Project templates');
    }

    public static function getModelLabel(): string
    {
        return __('project template');
    }

    public static function getPluralModelLabel(): string
    {
        return __('project templates');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Template'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label(__('Name'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('slug')
                        ->label(__('Slug'))
                        ->required()
                        ->maxLength(255)
                        ->alphaDash(),
                    Select::make('type')
                        ->label(__('Project type'))
                        ->options(self::typeOptions())
                        ->default(ProjectType::General->value)
                        ->required(),
                    Select::make('workspace_id')
                        ->label(__('Workspace'))
                        ->options(fn (): array => Workspace::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->placeholder(__('System template — offered everywhere')),
                    Textarea::make('description')
                        ->label(__('Description'))
                        ->rows(2)
                        ->maxLength(1000)
                        ->columnSpanFull(),
                    TextInput::make('icon')
                        ->label(__('Icon'))
                        ->maxLength(8)
                        ->helperText(__('A single emoji.')),
                    TextInput::make('color')
                        ->label(__('Colour'))
                        ->maxLength(32)
                        ->helperText(__('A palette name, e.g. brand or teal.')),
                    Toggle::make('is_active')
                        ->label(__('Offered when creating a project'))
                        ->default(true),
                    Toggle::make('is_system')
                        ->label(__('System template'))
                        ->helperText(__('Marks a template that ships with Planvio. Upgrades may replace it.')),
                ]),

            Section::make(__('Definition'))
                ->description(__('Statuses, milestones, tasks, tags and views to create with the project. Must be a JSON object.'))
                ->schema([
                    CodeEditor::make('definition')
                        ->label(__('JSON definition'))
                        ->language(Language::Json)
                        ->required()
                        // The column is cast to `array`; the editor speaks text. Both
                        // conversions live here so nothing else has to know.
                        ->formatStateUsing(self::encodeDefinition(...))
                        ->dehydrateStateUsing(self::decodeDefinition(...))
                        ->rule(self::jsonObjectRule()),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Template'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (ProjectTemplate $record): ?string => $record->description),
                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge()
                    ->formatStateUsing(fn (?ProjectType $state): string => $state?->label() ?? '')
                    ->color(fn (?ProjectType $state): string => PanelColor::for($state?->color())),
                TextColumn::make('workspace.name')
                    ->label(__('Availability'))
                    ->placeholder(__('Every workspace'))
                    ->searchable(),
                TextColumn::make('definition')
                    ->label(__('Contains'))
                    ->state(self::summarise(...))
                    ->placeholder(__('Empty')),
                IconColumn::make('is_system')
                    ->label(__('System'))
                    ->boolean()
                    ->falseColor('gray'),
                IconColumn::make('is_active')
                    ->label(__('Active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('Project type'))
                    ->options(self::typeOptions()),
                TernaryFilter::make('is_active')
                    ->label(__('Active')),
                TernaryFilter::make('is_system')
                    ->label(__('System template')),
            ])
            ->recordActions([
                EditAction::make(),
                ReplicateAction::make()
                    ->label(__('Duplicate'))
                    ->excludeAttributes(['slug'])
                    ->beforeReplicaSaved(function (ProjectTemplate $replica): void {
                        $replica->name = __(':name (copy)', ['name' => $replica->name]);
                        $replica->slug = $replica->slug.'-copy-'.uniqid();
                        $replica->is_system = false;
                    }),
                DeleteAction::make()
                    ->modalDescription(__('Projects already created from this template are untouched — a template is copied at creation, not linked.')),
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedSquares2x2)
            ->emptyStateHeading(__('No project templates'))
            ->emptyStateDescription(__('Templates seed a new project with its statuses, milestones and starting tasks.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjectTemplates::route('/'),
            'create' => CreateProjectTemplate::route('/create'),
            'edit' => EditProjectTemplate::route('/{record}/edit'),
        ];
    }

    /**
     * @return Builder<ProjectTemplate>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('workspace');
    }

    /* ------------------------------------------------------------------ *
     * Definition conversion
     * ------------------------------------------------------------------ */

    public static function encodeDefinition(mixed $state): string
    {
        if (is_string($state)) {
            return $state;
        }

        if (! is_array($state)) {
            return '{}';
        }

        return (string) json_encode(
            $state,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeDefinition(mixed $state): array
    {
        if (is_array($state)) {
            return $state;
        }

        if (! is_string($state) || trim($state) === '') {
            return [];
        }

        try {
            $decoded = json_decode($state, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Unreachable through the form — the rule below refuses invalid JSON first — but a
            // silent empty definition is a better failure than a 500 if it ever is reached.
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A one-line census of the definition, so the list says what a template actually builds.
     */
    private static function summarise(ProjectTemplate $record): string
    {
        $definition = $record->definition;

        if (! is_array($definition) || $definition === []) {
            return '';
        }

        $parts = [];

        foreach (['statuses', 'milestones', 'tasks', 'tags', 'views'] as $section) {
            $items = $definition[$section] ?? null;

            if (is_array($items) && $items !== []) {
                $parts[] = count($items).' '.$section;
            }
        }

        return implode(' · ', $parts);
    }

    private static function jsonObjectRule(): Closure
    {
        return static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
            // Reached after dehydration in some paths and before it in others, so both shapes
            // have to be accepted.
            if (is_array($value)) {
                return;
            }

            if (! is_string($value)) {
                $fail(__('The definition must be a JSON object.'));

                return;
            }

            try {
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $fail(__('That is not valid JSON: :message', ['message' => $exception->getMessage()]));

                return;
            }

            if (! is_array($decoded)) {
                $fail(__('The definition must be a JSON object, not a bare value.'));
            }
        };
    }

    /**
     * @return array<string, string>
     */
    private static function typeOptions(): array
    {
        $options = [];

        foreach (ProjectType::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        asort($options);

        return $options;
    }
}
