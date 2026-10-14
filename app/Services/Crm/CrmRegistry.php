<?php

namespace App\Services\Crm;

use App\Models\Company;

/**
 * Elenco dei driver CRM disponibili. Un'azienda ne sceglie uno (companies.crm_driver); aggiungere un CRM vuol dire
 * scrivere una classe che implementa CrmGateway e registrarla qui (o usare il driver «generic», senza codice).
 * Le funzioni oltre l'invio (documenti, template, moduli, firma) sono capacità opzionali dei driver: vedi Capabilities/.
 */
class CrmRegistry
{
    /** @var array<string, array{class: class-string<CrmGateway>, label: string}> */
    private array $drivers = [
        'mediafacile' => ['class' => MediafacileLeadGateway::class, 'label' => 'Mediafacile'],
        'generic' => ['class' => GenericRestGateway::class, 'label' => 'CRM generico (REST)'],
        'unicoloan' => ['class' => UnicoloanGateway::class, 'label' => 'unicoloan'],
    ];

    /** @param  class-string<CrmGateway>  $class */
    public function register(string $name, string $class, ?string $label = null): void
    {
        $this->drivers[$name] = ['class' => $class, 'label' => $label ?? $name];
    }

    public function has(string $name): bool
    {
        return isset($this->drivers[$name]);
    }

    /** @return array<string, string> nome => etichetta */
    public function labels(): array
    {
        return array_map(fn (array $driver): string => $driver['label'], $this->drivers);
    }

    public function make(string $name, Company $company): CrmGateway
    {
        return app()->makeWith($this->drivers[$name]['class'], ['company' => $company]);
    }

    /** @param  class-string  $capability una delle interfacce di Capabilities/ */
    public function supports(string $name, string $capability): bool
    {
        return $this->has($name) && is_subclass_of($this->drivers[$name]['class'], $capability);
    }
}
