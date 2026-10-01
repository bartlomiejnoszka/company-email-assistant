<?php

declare(strict_types=1);

namespace App\Configuration\Domain;

final class EditingSettings
{
    public const OPTIONS = [
        'formality' => ['formal', 'neutral', 'casual'],
        'length' => ['short', 'standard', 'detailed'],
        'addressing' => ['polite', 'impersonal'],
        'layout' => ['paragraphs', 'list', 'automatic'],
        'tone' => ['factual', 'warm', 'empathetic'],
        'vocabulary' => ['simple', 'technical'],
    ];
    public const DEFAULTS = ['formality' => 'neutral', 'length' => 'standard', 'addressing' => 'polite', 'layout' => 'automatic', 'tone' => 'warm', 'vocabulary' => 'simple'];

    public static function validate(mixed $settings, string $path): void
    {
        self::map($settings, $path);
        foreach ($settings as $key => $value) {
            if (!in_array($key, ['mode', 'style'], true)) {
                self::fail($path.'.'.$key, 'unknown editing field');
            }
        }
        if (array_key_exists('mode', $settings) && !in_array($settings['mode'], ['literal', 'mixed'], true)) {
            self::fail($path.'.mode', 'expected literal or mixed');
        }
        if (!array_key_exists('style', $settings)) {
            return;
        }
        self::map($settings['style'], $path.'.style');
        foreach ($settings['style'] as $key => $value) {
            if (isset(self::OPTIONS[$key])) {
                if (!in_array($value, self::OPTIONS[$key], true)) {
                    self::fail($path.'.style.'.$key, 'expected one of: '.implode(', ', self::OPTIONS[$key]));
                }
            } elseif ($key === 'instructions') {
                if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|__REQUIRED__|CHANGEME|TODO/i', $value)) {
                    self::fail($path.'.style.'.$key, 'approved instructions must be 1–1000 characters without required-data markers');
                }
            } else {
                self::fail($path.'.style.'.$key, 'unknown style option');
            }
        }
    }
    public static function effective(array $global, array $block): array
    {
        return ['mode' => $block['mode'] ?? $global['mode'] ?? 'literal', 'style' => array_replace(self::DEFAULTS, $global['style'] ?? [], $block['style'] ?? [])];
    }
    private static function map(mixed $value, string $path): void
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            self::fail($path, 'expected mapping');
        }
    }
    private static function fail(string $path, string $message): never
    {
        throw new \InvalidArgumentException($path.': '.$message);
    }
}
