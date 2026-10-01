<?php

declare(strict_types=1);

namespace App\Configuration\Infrastructure\Migration;

final class LegacyVocabulary
{
    private const VALUES = ['doslowna' => 'literal','mieszana' => 'mixed','formalna' => 'formal','neutralna' => 'neutral','swobodna' => 'casual','krotka' => 'short','standardowa' => 'standard','szczegolowa' => 'detailed','panstwo' => 'polite','bezosobowo' => 'impersonal','akapity' => 'paragraphs','lista' => 'list','automatyczny' => 'automatic','rzeczowy' => 'factual','zyczliwy' => 'warm','empatyczny' => 'empathetic','proste' => 'simple','specjalistyczne' => 'technical'];
    private const KEYS = ['formalnosc' => 'formality','dlugosc' => 'length','zwrot' => 'addressing','uklad' => 'layout','ton' => 'tone','slownictwo' => 'vocabulary','wskazowki' => 'instructions'];
    public static function editing(mixed $settings): mixed
    {
        if (!is_array($settings)) {
            return $settings;
        }
        if (isset($settings['mode'])) {
            $settings['mode'] = is_string($settings['mode']) ? (self::VALUES[$settings['mode']] ?? $settings['mode']) : $settings['mode'];
        }
        if (isset($settings['style']) && is_array($settings['style'])) {
            $style = [];
            foreach ($settings['style'] as $key => $value) {
                $style[self::KEYS[$key] ?? $key] = $key === 'wskazowki' || !is_string($value) ? $value : (self::VALUES[$value] ?? $value);
            }
            $settings['style'] = $style;
        }
        return $settings;
    }
    public static function convert(array $data): array
    {
        if (isset($data['reply_rules']['matching']) && $data['reply_rules']['matching'] === 'slowa') {
            $data['reply_rules']['matching'] = 'keywords';
        }
        if (isset($data['reply_rules']['editing'])) {
            $data['reply_rules']['editing'] = self::editing($data['reply_rules']['editing']);
        }
        foreach (['knowledge' => 'facts', 'reply_rules' => 'clarifications'] as $section => $collection) {
            foreach ($data[$section][$collection] as &$block) {
                if (isset($block['editing'])) {
                    $block['editing'] = self::editing($block['editing']);
                }
            }
            unset($block);
        }
        return $data;
    }
}
