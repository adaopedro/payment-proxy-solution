<?php

declare(strict_types=1);

function selectOptimalService(array $default, array $fallback): ?string
{

    if ($default['failing'] && $fallback['failing']) {
        return 'default';
        // return null;
    }

    if ($default['failing']) {
        return 'fallback';
    }

    if ($fallback['failing']) {
        return 'default';
    }

    return selectBestPerformingService($default, $fallback);
}

function selectBestPerformingService(array $default, array $fallback): string
{
    if ($default['latency'] <= $fallback['latency']) {
        return 'default';
    }

    if ($default['minResponseTime'] <= $fallback['minResponseTime']) {
        return 'default';
    }

    // if ($fallback['latency'] < $default['latency']) {
    //     return 'fallback';
    // }

    return 'fallback';
}

function convertFlattenedArrayToArgumentList(array $flattenArray): array
{
    $result = [];

    foreach ($flattenArray as $index => $value) {
        $result[] = $index;
        $result[] = $value;
    }

    return $result;
}

function flattenArray(array $array, string $prefix = ''): array
{
    $result = [];

    foreach ($array as $key => $value) {
        $newKey = $prefix === '' ? $key : "{$prefix}.{$key}";

        if (is_array($value) && !empty($value)) {
            $result = array_merge($result, flattenArray($value, $newKey));
        } else {
            // Redis streams precisam de valores string
            $result[$newKey] = $value === null ? '' : (string) $value;
        }
    }

    return $result;
}

function unflattenArray(array $array): array
{
    return array_column(
        array: array_chunk($array, 2),
        column_key: 1,
        index_key: 0
    );
}
