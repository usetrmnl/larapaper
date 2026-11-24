<?php

namespace App\Support;

use App\Models\Plugin;

class CustomFieldConditions
{
    /**
     * @param  array<string, mixed>  $field
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $configuration
     */
    public static function isVisible(array $field, array $fields, array $configuration): bool
    {
        $key = self::fieldKey($field);

        if ($key === null) {
            return true;
        }

        return ! isset(self::evaluate($fields, $configuration)['hidden'][$key]);
    }

    /**
     * Hidden fields are not required. A visible field is required when it is statically required,
     * or when a matching conditional rule lists it under required.
     *
     * @param  array<string, mixed>  $field
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $configuration
     */
    public static function isRequired(array $field, array $fields, array $configuration): bool
    {
        if (! self::isVisible($field, $fields, $configuration)) {
            return false;
        }

        $key = self::fieldKey($field);

        if ($key !== null && isset(self::evaluate($fields, $configuration)['required'][$key])) {
            return true;
        }

        return Plugin::isCustomFieldRequired($field);
    }

    /**
     * @param  array<string, mixed>  $field
     */
    public static function isConditionParent(array $field): bool
    {
        return isset($field['conditional_validation'])
            && is_array($field['conditional_validation'])
            && $field['conditional_validation'] !== [];
    }

    /**
     * @param  array<string, mixed>  $field
     */
    public static function fieldKey(array $field): ?string
    {
        $key = $field['keyname'] ?? $field['key'] ?? $field['name'] ?? null;

        if (! is_string($key) || $key === '') {
            return null;
        }

        return $key;
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $configuration
     * @return array{hidden: array<string, true>, required: array<string, true>}
     */
    private static function evaluate(array $fields, array $configuration): array
    {
        /** @var array<string, true> $visibleModeKeys */
        $visibleModeKeys = [];
        /** @var array<string, true> $revealedKeys */
        $revealedKeys = [];
        /** @var array<string, true> $hiddenByMatch */
        $hiddenByMatch = [];
        /** @var array<string, true> $requiredKeys */
        $requiredKeys = [];

        foreach ($fields as $field) {
            if (! is_array($field) || ! self::isConditionParent($field)) {
                continue;
            }

            $currentValue = self::resolvedValue($field, $configuration);

            foreach ($field['conditional_validation'] as $rule) {
                if (! is_array($rule) || ! array_key_exists('when', $rule)) {
                    continue;
                }

                $matches = self::valueMatches($currentValue, $rule['when']);

                foreach (self::keyList($rule['visible'] ?? null) as $key) {
                    $visibleModeKeys[$key] = true;

                    if ($matches) {
                        $revealedKeys[$key] = true;
                    }
                }

                if (! $matches) {
                    continue;
                }

                foreach (self::keyList($rule['hidden'] ?? null) as $key) {
                    $hiddenByMatch[$key] = true;
                }

                foreach (self::keyList($rule['required'] ?? null) as $key) {
                    $requiredKeys[$key] = true;
                }
            }
        }

        /** @var array<string, true> $hiddenKeys */
        $hiddenKeys = [];

        foreach (array_keys($visibleModeKeys) as $key) {
            if (! isset($revealedKeys[$key])) {
                $hiddenKeys[$key] = true;
            }
        }

        foreach (array_keys($hiddenByMatch) as $key) {
            if (! isset($visibleModeKeys[$key])) {
                $hiddenKeys[$key] = true;
            }
        }

        return [
            'hidden' => $hiddenKeys,
            'required' => $requiredKeys,
        ];
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $configuration
     */
    private static function resolvedValue(array $field, array $configuration): mixed
    {
        $key = self::fieldKey($field);

        if ($key !== null && array_key_exists($key, $configuration)) {
            return $configuration[$key];
        }

        if (($field['field_type'] ?? null) === 'boolean') {
            return Plugin::booleanFieldDefault($field);
        }

        return $field['default'] ?? '';
    }

    private static function valueMatches(mixed $current, mixed $expected): bool
    {
        if (is_array($current)) {
            foreach ($current as $item) {
                if (self::scalarMatches($item, $expected)) {
                    return true;
                }
            }

            return false;
        }

        return self::scalarMatches($current, $expected);
    }

    private static function scalarMatches(mixed $actual, mixed $expected): bool
    {
        $actualValue = self::stringify($actual);
        $expectedValue = self::stringify($expected);

        if ($actualValue === $expectedValue) {
            return true;
        }

        if ($actualValue === '' || $expectedValue === '') {
            return false;
        }

        return self::optionValue($actualValue) === self::optionValue($expectedValue);
    }

    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null || is_array($value)) {
            return '';
        }

        return (string) $value;
    }

    /**
     * Unlabeled select options are stored as a lowercased slug. Match that form so a label
     * such as "Polling" satisfies when: polling.
     */
    private static function optionValue(string $value): string
    {
        return mb_strtolower(str_replace(' ', '_', $value));
    }

    /**
     * @return list<string>
     */
    private static function keyList(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $keys = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $keys[] = $item;
            }
        }

        return $keys;
    }
}
