<?php

namespace App\Filament\Resources\Conversations\Pages;

use App\Filament\Resources\Conversations\ConversationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListConversations extends ListRecords
{
    protected static string $resource = ConversationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
