<?php

declare(strict_types=1);

namespace App\Interface\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

final class LocaleListener
{
    public function __construct(private readonly string $locale)
    {
    }
    #[AsEventListener(event: 'kernel.request', priority: 20)]
    public function apply(RequestEvent $event): void
    {
        $event->getRequest()->setLocale(in_array($this->locale, ['en','pl'], true) ? $this->locale : 'en');
    }
}
