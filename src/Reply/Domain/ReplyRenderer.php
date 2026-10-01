<?php

declare(strict_types=1);

namespace App\Reply\Domain;

use App\Configuration\Domain\CompanyConfig;

final class ReplyRenderer
{
    public function render(array $proposal, Selection $selection, CompanyConfig $config, ?array $rewrite = null): string
    {
        (new ProposalValidator())->validate($proposal, $selection);
        $editing = new ReplyEditing();
        $editable = $editing->editable($proposal, $selection, $config);
        if ($editable && $rewrite === null) {
            throw new \App\MailProcessing\Domain\ManualHandling('invalid_ai_rewrite');
        }
        $edits = $rewrite === null ? [] : $editing->validate($rewrite, $editable);
        $parts = [$config->rules['greeting']];
        foreach ($proposal['blocks'] as $ref) {
            $block = $selection->blocks()[$ref];
            $text = $edits[$ref] ?? $block['text'];
            if (str_starts_with($ref, 'price:')) {
                if ($block['kind'] === 'fixed') {
                    $text = strtr($text, ['{amount}' => $block['amount'], '{currency}' => $block['currency']]);
                }
                // Conditions are always visible; do not assert applicability or calculate totals.
                $text .= "\n".$block['conditions'];
            }
            $parts[] = $text;
        }
        return implode("\n\n", [...$parts, $config->rules['closing'], $config->company['signature']]);
    }
}
