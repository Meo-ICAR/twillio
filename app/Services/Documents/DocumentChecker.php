<?php

namespace App\Services\Documents;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Confronta i dati letti da un documento con quelli dichiarati sulla pratica (nessuna AI: regole fisse). */
class DocumentChecker
{
    private const EXPECTED = [
        'documento_identita' => ['identita', 'documento d\'identità'],
        'codice_fiscale' => ['codice_fiscale', 'tessera del codice fiscale'],
        'reddito' => ['reddito', 'documento di reddito'],
    ];

    private const DETECTED = [
        'identita' => 'documento d\'identità',
        'codice_fiscale' => 'tessera del codice fiscale',
        'reddito' => 'documento di reddito',
        'informativa' => 'informativa privacy',
        'altro' => 'altro tipo di documento',
    ];

    /**
     * @param  array<string,mixed>  $extracted  dati letti dal documento
     * @param  array<string,mixed>  $personal  dati personali dichiarati sulla pratica
     * @return list<string> difformità in italiano, vuoto se tutto coincide
     */
    public function check(string $kind, array $extracted, array $personal, ?string $today = null): array
    {
        [$expectedType, $expectedLabel] = self::EXPECTED[$kind] ?? [null, $kind];

        if (! ($extracted['legible'] ?? true)) {
            return ['Il documento non è leggibile: invia una foto più nitida, senza riflessi e con tutto il documento inquadrato.'];
        }

        $detected = $extracted['kind_detected'] ?? null;
        if ($detected === 'altro') {
            return ["Non riconosco il file come {$expectedLabel}: controlla di aver inviato il documento giusto."];
        }
        if ($detected && $expectedType && $detected !== $expectedType) {
            return ['Il file sembra un '.(self::DETECTED[$detected] ?? $detected).", non un {$expectedLabel}."];
        }

        $issues = [];
        $issues = array_merge($issues, $this->compareNames('Cognome', $extracted['surname'] ?? null, $personal['cognome'] ?? null));
        $issues = array_merge($issues, $this->compareNames('Nome', $extracted['name'] ?? null, $personal['nome'] ?? null));
        $issues = array_merge($issues, $this->compareCode('Codice fiscale', $extracted['fiscal_code'] ?? null, $personal['codice_fiscale'] ?? null));

        if ($kind === 'documento_identita') {
            $issues = array_merge($issues, $this->compareDates('Data di nascita', $extracted['birth_date'] ?? null, $personal['data_nascita'] ?? null));
            $issues = array_merge($issues, $this->compareCode('Numero documento', $extracted['document_number'] ?? null, $personal['documento_numero'] ?? null));
            $issues = array_merge($issues, $this->compareDates('Scadenza', $extracted['expiry_date'] ?? null, $personal['documento_scadenza'] ?? null));
            $issues = array_merge($issues, $this->expired($extracted['expiry_date'] ?? null, $today));
        }

        return $issues;
    }

    private function compareNames(string $label, ?string $onDocument, ?string $declared): array
    {
        if (blank($onDocument) || blank($declared)) {
            return [];
        }

        $a = $this->tokens($onDocument);
        $b = $this->tokens($declared);
        if (! array_diff($a, $b) || ! array_diff($b, $a)) {
            return [];
        }

        return [$this->mismatch($label, $onDocument, $declared)];
    }

    private function compareCode(string $label, ?string $onDocument, ?string $declared): array
    {
        if (blank($onDocument) || blank($declared)) {
            return [];
        }

        return $this->alnum($onDocument) === $this->alnum($declared) ? [] : [$this->mismatch($label, $onDocument, $declared)];
    }

    private function compareDates(string $label, ?string $onDocument, ?string $declared): array
    {
        if (blank($onDocument) || blank($declared)) {
            return [];
        }

        return $this->normalizeDate($onDocument) === $this->normalizeDate($declared) ? [] : [$this->mismatch($label, $onDocument, $declared)];
    }

    private function expired(?string $expiry, ?string $today): array
    {
        if (blank($expiry) || ! preg_match('/(\d{1,2})\D(\d{1,2})\D(\d{4})/', $expiry, $m)) {
            return [];
        }

        $date = Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1])->startOfDay();
        $now = $today ? Carbon::parse($today)->startOfDay() : today();

        return $date->lt($now) ? ["Il documento risulta scaduto (scadenza {$m[1]}/{$m[2]}/{$m[3]}): serve un documento in corso di validità."] : [];
    }

    private function mismatch(string $label, string $onDocument, string $declared): string
    {
        return "{$label}: sul documento «{$onDocument}», dichiarato «{$declared}»";
    }

    /** @return string[] */
    private function tokens(string $text): array
    {
        return array_values(array_filter(preg_split('/[^A-Z]+/', strtoupper(Str::ascii($text)))));
    }

    private function alnum(string $text): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($text));
    }

    private function normalizeDate(string $date): string
    {
        return preg_match('/(\d{1,2})\D(\d{1,2})\D(\d{4})/', $date, $m)
            ? sprintf('%02d/%02d/%04d', $m[1], $m[2], $m[3])
            : preg_replace('/\D/', '', $date);
    }
}
