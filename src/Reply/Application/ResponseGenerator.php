<?php

declare(strict_types=1);

namespace App\Reply\Application;

use App\Reply\Application\Port\DraftAi;
use App\Reply\Domain\{FactSelector, ProposalValidator, ReplyRenderer, TopicMatchValidator, ReplyEditing};
use App\MailProcessing\Domain\ManualHandling;
use App\Configuration\Domain\EditingSettings;
use App\Configuration\Domain\CompanyConfig;

/** Shared, mailbox-independent approved-content response pipeline. */
final class ResponseGenerator
{
    public function __construct(
        private readonly DraftAi $ai,
        private readonly FactSelector $selector,
        private readonly ProposalValidator $validator,
        private readonly ReplyRenderer $renderer,
        private readonly \App\Reply\Application\Port\Clock|\Closure|null $clock = null,
    ) {
    }

    public function preflight(): void
    {
        $this->ai->preflight();
    }
    private function now(): \DateTimeImmutable
    {
        return $this->clock instanceof \App\Reply\Application\Port\Clock ? $this->clock->now() : ($this->clock ? ($this->clock)() : new \DateTimeImmutable());
    }

    public function generate(CompanyConfig $config, string $subject, string $text): \App\Reply\Domain\ReplyResult
    {
        $matchText = $subject."\n".$text;
        $matchedTopics = null;
        $this->selector->assertAllowed($config, $matchText);
        if (($config->rules['matching'] ?? 'keywords') === 'ai') {
            $matchedTopics = (new TopicMatchValidator())->validate($this->ai->matchTopics($matchText, $config), $config);
        }
        $selection = $this->selector->select($config, $matchText, $this->now(), $matchedTopics);
        if (!$selection->blocks()) {
            throw new ManualHandling('insufficient_knowledge');
        }
        $proposal = $this->ai->propose($text, $selection, $config);
        // AI calls can cross an office-local midnight: recheck approved content validity.
        $selection = $this->selector->select($config, $matchText, $this->now(), $matchedTopics);
        $this->validator->validate($proposal, $selection);
        if ($proposal['outcome'] === 'manual') {
            throw new ManualHandling($proposal['reason']);
        }
        $editing = new ReplyEditing();
        $editable = $editing->editable($proposal, $selection, $config);
        $rewrite = null;
        if ($editable) {
            $rewrite = $this->ai->rewrite($text, $editable, $config);
            $selection = $this->selector->select($config, $matchText, $this->now(), $matchedTopics);
            $editing->validate($rewrite, $editing->editable($proposal, $selection, $config));
        }
        $preferences = [];
        foreach ($proposal['blocks'] as $ref) {
            $preferences[$ref] = str_starts_with($ref, 'price:')
                ? ['mode' => 'literal', 'protected' => true]
                : EditingSettings::effective($config->rules['editing'] ?? [], $selection->blocks()[$ref]['editing'] ?? []);
        }
        return new \App\Reply\Domain\ReplyResult(
            $proposal['outcome'],
            $proposal['reason'],
            $this->renderer->render($proposal, $selection, $config, $rewrite),
            $selection->topics,
            $proposal['blocks'],
            $preferences,
            $rewrite !== null,
        );
    }
}
