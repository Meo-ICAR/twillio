<?php

namespace App\Filament\Resources\Attachments;

use App\Filament\Resources\Attachments\Pages\ListAttachments;
use App\Filament\Resources\Attachments\Pages\ViewAttachment;
use App\Filament\Resources\Attachments\Schemas\AttachmentInfolist;
use App\Filament\Resources\Attachments\Tables\AttachmentsTable;
use App\Models\Attachment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AttachmentResource extends Resource
{
    protected static ?string $model = Attachment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperClip;

    protected static ?string $modelLabel = 'allegato';

    protected static ?string $pluralModelLabel = 'allegati';

    protected static bool $shouldRegisterNavigation = false;

    /** Gli allegati arrivano solo da WhatsApp. */
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
        return AttachmentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AttachmentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttachments::route('/'),
            'view' => ViewAttachment::route('/{record}'),
        ];
    }
}
