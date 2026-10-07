<?php

namespace App\Filament\Resources\Flows;

use App\Filament\Resources\Flows\Pages\EditFlow;
use App\Filament\Resources\Flows\Pages\ListFlows;
use App\Filament\Resources\Flows\RelationManagers\NodesRelationManager;
use App\Filament\Resources\Flows\Schemas\FlowForm;
use App\Filament\Resources\Flows\Tables\FlowsTable;
use App\Models\Flow;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FlowResource extends Resource
{
    protected static ?string $model = Flow::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $modelLabel = 'percorso';

    protected static ?string $pluralModelLabel = 'percorsi di conversazione';

    protected static ?string $navigationLabel = 'Percorsi di conversazione';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 6;

    /** I percorsi nascono dall'importazione (php artisan flows:import): dal pannello si modificano, non si creano né si eliminano. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return FlowForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FlowsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [NodesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFlows::route('/'),
            'edit' => EditFlow::route('/{record}/edit'),
        ];
    }
}
