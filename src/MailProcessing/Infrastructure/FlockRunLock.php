<?php

declare(strict_types=1);

namespace App\MailProcessing\Infrastructure;

use App\MailProcessing\Application\Port\{RunLock, ProcessingState};
use Symfony\Component\Lock\{LockFactory, LockInterface};
use Symfony\Component\Lock\Store\FlockStore;

final class FlockRunLock implements RunLock
{
    private ?LockInterface $lock = null;
    public function __construct(private readonly ProcessingState $state)
    {
    }
    public function acquire(): bool
    {
        $this->lock = (new LockFactory(new FlockStore($this->state->lockPath())))->createLock($this->state->lockKey());
        return $this->lock->acquire();
    }
    public function release(): void
    {
        $this->lock?->release();
    }
}
