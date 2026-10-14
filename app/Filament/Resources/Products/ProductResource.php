<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Company;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Prodotti del catalogo per settore: si associano alle company dalla loro scheda. */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?int $navigationSort = 4;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Prodotti';

    protected static ?string $modelLabel = 'prodotto';

    protected static ?string $pluralModelLabel = 'prodotti';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')->label('Settore')->options(Company::TYPES)->required(),
            TextInput::make('name')->label('Nome')->required()->maxLength(255),
            TextInput::make('code')->label('Codice')->maxLength(40),
            Textarea::make('description')->label('Descrizione'),
            Toggle::make('is_active')->label('Attivo')->default(true),
            KeyValue::make('params')->label('Parametri (fornitura, prezzo, quota fissa, durata, bonus…)')
                ->helperText('Energia: fornitura (luce/gas), price (€/kWh o €/Smc), fixed_monthly, bonus, duration_months, provider.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('type')
            ->columns([
                TextColumn::make('type')->label('Settore')->badge()->formatStateUsing(fn (string $state) => Company::TYPES[$state] ?? $state)->sortable(),
                TextColumn::make('name')->label('Nome')->searchable(),
                TextColumn::make('code')->label('Codice')->toggleable(),
                IconColumn::make('is_active')->label('Attivo')->boolean(),
                TextColumn::make('companies_count')->label('Aziende')->counts('companies'),
            ])
            ->filters([SelectFilter::make('type')->label('Settore')->options(Company::TYPES)])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
