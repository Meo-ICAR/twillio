<?php

namespace App\Filament\Resources\FinanziamentoDocuments;

use App\Filament\Resources\FinanziamentoDocuments\Pages\CreateFinanziamentoDocument;
use App\Filament\Resources\FinanziamentoDocuments\Pages\EditFinanziamentoDocument;
use App\Filament\Resources\FinanziamentoDocuments\Pages\ListFinanziamentoDocuments;
use App\Filament\Resources\FinanziamentoDocuments\Schemas\FinanziamentoDocumentForm;
use App\Filament\Resources\FinanziamentoDocuments\Tables\FinanziamentoDocumentsTable;
use App\Models\FinanziamentoDocument;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FinanziamentoDocumentResource extends Resource
{
    protected static ?string $model = FinanziamentoDocument::class;

    protected static ?string $modelLabel = 'documento del finanziamento';

    protected static ?string $pluralModelLabel = 'documenti per finanziamento';

    protected static ?string $navigationLabel = 'Documenti per finanziamento';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return FinanziamentoDocumentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FinanziamentoDocumentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFinanziamentoDocuments::route('/'),
            'create' => CreateFinanziamentoDocument::route('/create'),
            'edit' => EditFinanziamentoDocument::route('/{record}/edit'),
        ];
    }
}
