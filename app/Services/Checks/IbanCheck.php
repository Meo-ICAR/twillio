<?php

namespace App\Services\Checks;

use App\Services\Conversation\Iban;

class IbanCheck implements NodeCheck
{
    public function label(): string
    {
        return 'IBAN';
    }

    public function description(): string
    {
        return 'Verifica formato e checksum dell\'IBAN.';
    }

    public function derives(): array
    {
        return [];
    }

    public function passes(string $value, CheckContext $ctx): bool
    {
        return Iban::isValid($value);
    }
}
