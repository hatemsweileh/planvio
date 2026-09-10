<?php

declare(strict_types=1);

namespace App\Enums;

enum CustomFieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
    case Select = 'select';
    case MultiSelect = 'multi_select';
    case Checkbox = 'checkbox';
    case Url = 'url';

    public function label(): string
    {
        return __('enums.custom_field_type.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Text => 'gray',
            self::Number => 'blue',
            self::Date => 'purple',
            self::Select => 'teal',
            self::MultiSelect => 'brand',
            self::Checkbox => 'green',
            self::Url => 'amber',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Text => 'heroicon-o-document-text',
            self::Number => 'heroicon-o-hashtag',
            self::Date => 'heroicon-o-calendar',
            self::Select => 'heroicon-o-chevron-up-down',
            self::MultiSelect => 'heroicon-o-queue-list',
            self::Checkbox => 'heroicon-o-check-circle',
            self::Url => 'heroicon-o-link',
        };
    }

    /**
     * Whether the type is configured with a fixed list of choices.
     */
    public function hasOptions(): bool
    {
        return match ($this) {
            self::Select, self::MultiSelect => true,
            default => false,
        };
    }

    /**
     * The `custom_field_values` column this type is stored in.
     */
    public function valueColumn(): string
    {
        return match ($this) {
            self::Text, self::Url, self::Select => 'value_text',
            self::Number => 'value_number',
            self::Date => 'value_date',
            self::Checkbox => 'value_bool',
            self::MultiSelect => 'value_json',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
