<?php

declare(strict_types=1);

namespace App\Reply\Domain;

use App\MailProcessing\Domain\ManualHandling;

final class ProposalValidator
{
    public function validate(array $proposal, Selection $selection): void
    {
        $keys = ['outcome', 'blocks', 'used_fact_ids', 'used_price_ids', 'clarification_ids', 'reason'];
        if (array_diff($keys, array_keys($proposal)) || array_diff(array_keys($proposal), $keys)) {
            $this->invalid();
        }
        if (!in_array($proposal['outcome'], ['reply', 'clarify', 'manual'], true) || !in_array($proposal['reason'], ['supported', 'missing_details', 'insufficient_knowledge', 'requires_staff'], true)) {
            $this->invalid();
        }
        foreach (['blocks', 'used_fact_ids', 'used_price_ids', 'clarification_ids'] as $key) {
            if (!is_array($proposal[$key]) || !array_is_list($proposal[$key]) || count($proposal[$key]) > 30) {
                $this->invalid();
            }
            foreach ($proposal[$key] as $id) {
                if (!is_string($id)) {
                    $this->invalid();
                }
            }
            if (count(array_unique($proposal[$key])) !== count($proposal[$key])) {
                $this->invalid();
            }
        }
        $actual = ['fact' => [], 'price' => [], 'clarification' => []];
        foreach ($proposal['blocks'] as $ref) {
            if (!isset($selection->blocks()[$ref])) {
                $this->invalid();
            }
            [$type, $id] = explode(':', $ref, 2);
            $actual[$type][] = $id;
        }
        foreach (['fact' => 'used_fact_ids', 'price' => 'used_price_ids', 'clarification' => 'clarification_ids'] as $type => $field) {
            $reported = $proposal[$field];
            sort($reported);
            sort($actual[$type]);
            if ($actual[$type] !== $reported) {
                $this->invalid();
            }
        }
        if ($proposal['outcome'] === 'manual') {
            if ($proposal['blocks'] || !in_array($proposal['reason'], ['insufficient_knowledge', 'requires_staff'], true)) {
                $this->invalid();
            }
        } elseif (!$proposal['blocks']) {
            $this->invalid();
        }
        if ($proposal['outcome'] === 'reply' && $proposal['reason'] !== 'supported') {
            $this->invalid();
        }
        if ($proposal['outcome'] === 'clarify' && $proposal['reason'] !== 'missing_details') {
            $this->invalid();
        }
        if ($proposal['outcome'] === 'clarify' && !$actual['clarification']) {
            $this->invalid();
        }
        if ($proposal['outcome'] === 'reply' && ($actual['clarification'] || (!$actual['fact'] && !$actual['price']))) {
            $this->invalid();
        }
    }
    private function invalid(): never
    {
        throw new ManualHandling('invalid_ai_output');
    }
}
