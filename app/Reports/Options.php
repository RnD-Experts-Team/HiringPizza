<?php

declare(strict_types=1);

namespace App\Reports;

use BackedEnum;

/** The `options` of an enum report column: [{value, label}, ...]. */
final class Options
{
    /**
     * @param  class-string<BackedEnum>  $enum
     * @return list<array{value: string, label: string}>
     */
    public static function of(string $enum): array
    {
        return array_map(fn (BackedEnum $case) => self::option((string) $case->value), $enum::cases());
    }

    /**
     * Several enums sharing one column (a value in more than one is listed once).
     *
     * @param  list<class-string<BackedEnum>>  $enums
     * @return list<array{value: string, label: string}>
     */
    public static function union(array $enums): array
    {
        $options = [];
        foreach ($enums as $enum) {
            foreach (self::of($enum) as $option) {
                $options[$option['value']] = $option;
            }
        }

        return array_values($options);
    }

    /**
     * Values that are not an enum's (e.g. a workflow state derived in SQL).
     *
     * @param  list<string>  $values
     * @return list<array{value: string, label: string}>
     */
    public static function values(array $values): array
    {
        return array_map(fn (string $value) => self::option($value), $values);
    }

    private static function option(string $value): array
    {
        return ['value' => $value, 'label' => ucfirst(str_replace('_', ' ', $value))];
    }
}
