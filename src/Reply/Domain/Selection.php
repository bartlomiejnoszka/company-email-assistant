<?php

declare(strict_types=1);

namespace App\Reply\Domain;

final readonly class Selection
{
    public function __construct(public array $facts, public array $prices, public array $clarifications, public array $topics)
    {
    }
    public function blocks(): array
    {
        $blocks = [];
        foreach (['fact' => $this->facts, 'price' => $this->prices, 'clarification' => $this->clarifications] as $type => $items) {
            foreach ($items as $id => $item) {
                $blocks[$type.':'.$id] = $item;
            }
        }
        return $blocks;
    }
}
