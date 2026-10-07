<?php

namespace App\Filament\Resources\FlowChecks;

use App\Filament\Resources\FlowChecks\Pages\EditFlowCheck;
use App\Filament\Resources\FlowChecks\Pages\ListFlowChecks;
use App\Filament\Resources\FlowChecks\Schemas\FlowCheckForm;
use App\Filament\Resources\FlowChecks\Tables\FlowChecksTable;
use App\Models\FlowCheck;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FlowCheckResource extends Resource
{
    protected static ?string $model = FlowCheck::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $modelLabel = 'controllo';

    protected static ?string $pluralModelLabel = 'controlli sulle risposte';

    protected static ?string $navigationLabel = 'Controlli sulle risposte';

    protected static ?string $recordTitleAttribute = 'code';

    protected static ?int $navigationSort = 7;

    /** I controlli nascono da una classe: si scrive la classe e il pulsante «Cerca nuovi controlli» la aggiunge all'elenco. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return FlowCheckForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FlowChecksTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFlowChecks::route('/'),
            'edit' => EditFlowCheck::route('/{record}/edit'),
        ];
    }
}
