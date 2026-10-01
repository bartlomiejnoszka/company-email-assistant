<?php

declare(strict_types=1);

namespace App\Reply\Domain;

use App\MailProcessing\Domain\ManualHandling;
use App\Configuration\Domain\EditingSettings;
use App\Configuration\Domain\CompanyConfig;

final class ReplyEditing
{
    /** Only selected, explicitly editable non-price blocks can cross the rewrite boundary. */
    public function editable(array $proposal, Selection $selection, CompanyConfig $config): array
    {
        (new ProposalValidator())->validate($proposal, $selection);
        $editable = [];
        foreach ($proposal['blocks'] as $ref) {
            if (str_starts_with($ref, 'price:')) {
                continue;
            }
            $block = $selection->blocks()[$ref];
            $settings = EditingSettings::effective($config->rules['editing'] ?? [], $block['editing'] ?? []);
            if ($settings['mode'] === 'mixed') {
                $editable[$ref] = ['text' => $block['text'], 'style' => $settings['style']];
            }
        }
        return $editable;
    }

    /** Structural and lexical checks are guardrails, not a proof of semantic equivalence. */
    public function validate(array $result, array $editable): array
    {
        if (array_diff(['outcome', 'edits'], array_keys($result)) || array_diff(array_keys($result), ['outcome', 'edits'])) {
            $this->invalid();
        }
        if (!in_array($result['outcome'], ['edited', 'manual'], true) || !is_array($result['edits']) || !array_is_list($result['edits'])) {
            $this->invalid();
        }
        if ($result['outcome'] === 'manual') {
            if ($result['edits'] !== []) {
                $this->invalid();
            }
            throw new ManualHandling('ai_rewrite_requires_staff');
        }
        if (count($result['edits']) !== count($editable)) {
            $this->invalid();
        }
        $edits = [];
        $total = 0;
        foreach ($result['edits'] as $edit) {
            if (!is_array($edit) || array_diff(['id', 'text'], array_keys($edit)) || array_diff(array_keys($edit), ['id', 'text'])) {
                $this->invalid();
            }
            $id = $edit['id'];
            $text = $edit['text'];
            if (!is_string($id) || !isset($editable[$id]) || isset($edits[$id]) || !is_string($text) || !mb_check_encoding($text, 'UTF-8') || trim($text) === '' || mb_strlen($text) > 6000) {
                $this->invalid();
            }
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|[<>]|```|(?:fact|price|clarification):/u', $text)) {
                $this->invalid();
            }
            // Monetary claims belong exclusively to immutable price blocks. This catches common
            // forms, but cannot detect every monetary/legal claim expressed in natural language.
            if (preg_match('/\b(?:PLN|EUR|USD|GBP|CHF|zł\p{L}*|euro|dolar\p{L}*|gratis|bezpłat\p{L}*)\b|[%€$£]/iu', $text)) {
                $this->invalid();
            }
            // No new numeric values (including ones found only in the untrusted customer mail).
            preg_match_all('/\d+(?:[.,:\/-]\d+)*/u', $editable[$id]['text'], $allowed);
            preg_match_all('/\d+(?:[.,:\/-]\d+)*/u', $text, $actual);
            if (array_diff($actual[0], $allowed[0])) {
                $this->invalid();
            }
            preg_match_all('~https?://\S+|www\.\S+|[\w.+-]+@[\w.-]+~iu', $text, $links);
            foreach ($links[0] as $link) {
                if (!str_contains($editable[$id]['text'], $link)) {
                    $this->invalid();
                }
            }
            $total += mb_strlen($text);
            if ($total > 24000) {
                $this->invalid();
            }
            $edits[$id] = str_replace(["\r\n", "\r"], "\n", trim($text));
        }
        return $edits;
    }
    private function invalid(): never
    {
        throw new ManualHandling('invalid_ai_rewrite');
    }
}
