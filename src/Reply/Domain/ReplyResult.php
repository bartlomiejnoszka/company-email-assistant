<?php

declare(strict_types=1);

namespace App\Reply\Domain;

final readonly class ReplyResult
{
    public function __construct(public string $outcome, public string $reason, public string $response, public array $matchedTopics, public array $selectedBlockIds, public array $editingPreferences, public bool $rewritten)
    {
    }
    public function toArray(): array
    {
        return ['outcome' => $this->outcome, 'reason' => $this->reason, 'response' => $this->response, 'matched_topics' => $this->matchedTopics, 'selected_block_ids' => $this->selectedBlockIds, 'editing_preferences' => $this->editingPreferences, 'rewritten' => $this->rewritten];
    }
}
