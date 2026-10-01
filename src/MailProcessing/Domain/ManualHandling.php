<?php

declare(strict_types=1);

namespace App\MailProcessing\Domain;

/** The message is a fixed, safe reason code, never untrusted content. */
final class ManualHandling extends \RuntimeException
{
}
