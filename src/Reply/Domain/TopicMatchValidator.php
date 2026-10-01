<?php

declare(strict_types=1);

namespace App\Reply\Domain;

use App\MailProcessing\Domain\ManualHandling;
use App\Configuration\Domain\CompanyConfig;

final class TopicMatchValidator
{
    public function validate(array $result, CompanyConfig $config): array
    {
        $fields = ['outcome', 'confidence', 'topic_ids'];
        if (array_diff($fields, array_keys($result)) || array_diff(array_keys($result), $fields)) {
            $this->invalid();
        }
        if (!in_array($result['outcome'], ['matched', 'manual'], true) || !in_array($result['confidence'], ['high', 'uncertain'], true)) {
            $this->invalid();
        }
        $ids = $result['topic_ids'];
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 10) {
            $this->invalid();
        }
        foreach ($ids as $id) {
            if (!is_string($id) || !isset($config->rules['topics'][$id])) {
                $this->invalid();
            }
        }
        if (count(array_unique($ids)) !== count($ids)) {
            $this->invalid();
        }
        if ($result['outcome'] === 'manual') {
            if ($ids || $result['confidence'] !== 'uncertain') {
                $this->invalid();
            }
            throw new ManualHandling('topic_match_uncertain');
        }
        if (!$ids || $result['confidence'] !== 'high') {
            $this->invalid();
        }
        // High is the model's assessment, not a measured probability or factual guarantee.
        return $ids;
    }
    private function invalid(): never
    {
        throw new ManualHandling('invalid_ai_topic_match');
    }
}
