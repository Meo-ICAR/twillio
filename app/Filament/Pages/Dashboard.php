<?php

namespace App\Filament\Pages;

use App\Services\SystemHealth;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected function getHeaderActions(): array
    {
        return [
            Action::make('verificaWhatsapp')->label('Verifica WhatsApp')->icon('heroicon-o-chat-bubble-left-right')->color('gray')
                ->action(fn () => $this->report('WhatsApp', app(SystemHealth::class)->checkWhatsApp())),
            Action::make('verificaAi')->label('Verifica AI')->icon('heroicon-o-sparkles')->color('gray')
                ->requiresConfirmation()->modalDescription('Fa una richiesta minima ad Anthropic (frazioni di centesimo) per controllare chiave e credito.')
                ->action(fn () => $this->report('AI', app(SystemHealth::class)->checkAi())),
        ];
    }

    /** @param array{ok: bool, detail: string} $result */
    private function report(string $what, array $result): void
    {
        Notification::make()->title($what.($result['ok'] ? ': funziona' : ': problema'))->body($result['detail'])
            ->{$result['ok'] ? 'success' : 'danger'}()->persistent()->send();

        $this->redirect(static::getUrl());
    }
}
