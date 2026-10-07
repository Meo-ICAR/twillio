<?php

namespace App\Filament\Resources\Attachments\Tables;

use App\Models\Attachment;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class AttachmentsTable
{
    public const STATUSES = [
        'ricevuto' => 'Ricevuto',
        'verificato' => 'Verificato',
        'difforme' => 'Difforme',
        'non_leggibile' => 'Non leggibile',
        'non_analizzato' => 'Non analizzato',
    ];

    public const COLORS = ['verificato' => 'success', 'difforme' => 'danger', 'non_leggibile' => 'warning', 'non_analizzato' => 'gray'];

    public const KINDS = [
        'informativa' => 'Informativa privacy',
        'documento_identita' => 'Documento d\'identità',
        'codice_fiscale' => 'Codice fiscale',
        'reddito' => 'Documento di reddito',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('received_at', 'desc')
            ->columns([
                TextColumn::make('loanRequest.code')->label('Pratica')->searchable(),
                TextColumn::make('kind')->label('Tipo')->formatStateUsing(fn (string $state) => self::KINDS[$state] ?? $state),
                TextColumn::make('status')->label('Esito')->badge()->color(fn (string $state) => self::COLORS[$state] ?? 'gray')
                    ->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state),
                TextColumn::make('mime')->label('Formato'),
                TextColumn::make('received_at')->label('Ricevuto il')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('kind')->label('Tipo')->options(self::KINDS),
                SelectFilter::make('status')->label('Esito')->options(self::STATUSES),
            ])
            ->recordActions([ViewAction::make(), self::downloadAction()]);
    }

    public static function downloadAction(): Action
    {
        return Action::make('download')
            ->label('Scarica')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->action(fn (Attachment $record) => Storage::disk('local')->download($record->path, basename($record->path)));
    }
}
