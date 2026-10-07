<?php

namespace App\Filament\Resources\Fornitori;

use App\Filament\Resources\Fornitori\Pages\ListFornitori;
use App\Filament\Resources\Fornitori\Pages\ViewFornitore;
use App\Models\Fornitore;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Produttori (agenti e collaboratori): anagrafica in sola lettura, arriva dal gestionale. */
class FornitoreResource extends Resource
{
    protected static ?string $model = Fornitore::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?int $navigationSort = 3;

    protected static string|UnitEnum|null $navigationGroup = 'Anagrafiche';

    protected static ?string $navigationLabel = 'Produttori';

    protected static ?string $modelLabel = 'produttore';

    protected static ?string $pluralModelLabel = 'produttori';

    protected static ?string $slug = 'produttori';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Produttore')->columns(3)->schema([
                TextEntry::make('name')->label('Denominazione')->placeholder('-'),
                TextEntry::make('nome')->label('Referente')->placeholder('-'),
                TextEntry::make('type')->label('Tipo')->placeholder('-'),
                TextEntry::make('tel')->label('Cellulare')->placeholder('-'),
                TextEntry::make('email')->label('Email')->placeholder('-'),
                TextEntry::make('pec')->label('PEC')->placeholder('-'),
                TextEntry::make('piva')->label('Partita IVA')->placeholder('-'),
                TextEntry::make('comune')->label('Comune')->placeholder('-'),
                IconEntry::make('is_active')->label('Attivo')->boolean(),
            ]),
            Section::make('Iscrizioni')->columns(3)->schema([
                TextEntry::make('oam')->label('OAM')->placeholder('-'),
                TextEntry::make('oam_at')->label('OAM dal')->date()->placeholder('-'),
                TextEntry::make('numero_iscrizione_rui')->label('RUI')->placeholder('-'),
                TextEntry::make('ivass')->label('IVASS')->placeholder('-'),
                TextEntry::make('stipulated_at')->label('Convenzione dal')->date()->placeholder('-'),
                TextEntry::make('dismissed_at')->label('Cessato il')->date()->placeholder('-'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Denominazione')->searchable()->placeholder('-')->description(fn (Fornitore $r) => $r->nome),
                TextColumn::make('type')->label('Tipo')->badge()->placeholder('-'),
                TextColumn::make('tel')->label('Cellulare')->searchable()->placeholder('-'),
                TextColumn::make('email')->label('Email')->searchable()->placeholder('-'),
                TextColumn::make('oam')->label('OAM')->placeholder('-'),
                IconColumn::make('is_active')->label('Attivo')->boolean(),
            ])
            ->filters([TernaryFilter::make('is_active')->label('Attivo')])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFornitori::route('/'),
            'view' => ViewFornitore::route('/{record}'),
        ];
    }
}
