<?php

declare(strict_types=1);

namespace App\MailProcessing\Domain;

final readonly class EmailAddress
{
    public function __construct(private string $address, private string $name = '')
    {
        if (!filter_var($address, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $name)) {
            throw new \InvalidArgumentException('Invalid email address.');
        }
    }
    public function getAddress(): string
    {
        return $this->address;
    }
    public function getName(): string
    {
        return $this->name;
    }
}
