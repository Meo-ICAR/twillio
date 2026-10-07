<?php

namespace App\Filament\Resources\LoanRequests;

use App\Filament\Resources\LoanRequests\Pages\EditLoanRequest;
use App\Filament\Resources\LoanRequests\Pages\ListLoanRequests;
use App\Filament\Resources\LoanRequests\Pages\ViewLoanRequest;
use App\Filament\Resources\LoanRequests\RelationManagers\AttachmentsRelationManager;
use App\Filament\Resources\LoanRequests\Schemas\LoanRequestForm;
use App\Filament\Resources\LoanRequests\Schemas\LoanRequestInfolist;
use App\Filament\Resources\LoanRequests\Tables\LoanRequestsTable;
use App\Models\LoanRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class LoanRequestResource extends Resource
{
    protected static ?string $model = LoanRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $modelLabel = 'pratica';

    protected static ?string $pluralModelLabel = 'pratiche';

    protected static ?string $recordTitleAttribute = 'code';

    protected static ?int $navigationSort = 1;

    /** Le pratiche nascono dal bot: il codice è generato dalla conversazione. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return LoanRequestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return LoanRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LoanRequestsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [AttachmentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLoanRequests::route('/'),
            'view' => ViewLoanRequest::route('/{record}'),
            'edit' => EditLoanRequest::route('/{record}/edit'),
        ];
    }
}
