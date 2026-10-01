<?php

declare(strict_types=1);

namespace App\Reply\Domain;

use App\MailProcessing\Domain\ManualHandling;
use App\Configuration\Domain\CompanyConfig;

final class FactSelector
{
    public function assertAllowed(CompanyConfig $config, string $text): void
    {
        foreach (['excluded_terms' => 'excluded_topic', 'manual_terms' => 'office_manual_rule'] as $field => $reason) {
            if ($this->matches($text, $config->rules[$field])) {
                throw new ManualHandling($reason);
            }
        }
    }
    public function select(CompanyConfig $config, string $text, \DateTimeImmutable $now, ?array $matchedTopics = null): Selection
    {
        $this->assertAllowed($config, $text);
        $topics = $matchedTopics ?? [];
        if ($matchedTopics === null) {
            foreach ($config->rules['topics'] as $id => $topic) {
                if ($this->matches($text, $topic['terms'])) {
                    $topics[] = $id;
                }
            }
        } else {
            if (!array_is_list($topics) || count(array_unique($topics, SORT_REGULAR)) !== count($topics)) {
                throw new ManualHandling('invalid_ai_topic_match');
            }
            foreach ($topics as $id) {
                if (!is_string($id) || !isset($config->rules['topics'][$id])) {
                    throw new ManualHandling('invalid_ai_topic_match');
                }
            }
        }
        $superseded = [];
        foreach ($topics as $id) {
            $superseded = [...$superseded, ...($config->rules['topics'][$id]['supersedes'] ?? [])];
        }
        $topics = array_values(array_diff($topics, $superseded));
        if (!$topics) {
            throw new ManualHandling('no_matching_topic');
        }
        $today = $now->setTimezone(new \DateTimeZone($config->company['timezone']))->format('Y-m-d');
        $filter = static fn (array $item): bool => (bool) array_intersect($topics, $item['topics'])
            && (!isset($item['valid_from']) || $item['valid_from'] <= $today)
            && (!isset($item['valid_until']) || $item['valid_until'] >= $today);
        return new Selection(array_filter($config->knowledge, $filter), array_filter($config->pricing, $filter), array_filter($config->rules['clarifications'], $filter), $topics);
    }
    private function matches(string $text, array $terms): bool
    {
        $text = mb_strtolower(preg_replace('/\s+/u', ' ', $text));
        foreach ($terms as $term) {
            $term = mb_strtolower(preg_replace('/\s+/u', ' ', trim($term)));
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/u', $text)) {
                return true;
            }
        }
        return false;
    }
}
