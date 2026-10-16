<?php

namespace App\Filament\Resources\LoanRequests\RelationManagers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Unico\Core\Filament\RelationManagers\DocumentsRelationManager;

/**
 * Scheda documenti di unico-core sulla pratica. La visibilità dipende dalle tabelle del core (piano e permessi): dove non ci sono,
 * per esempio in un ambiente senza il database condiviso, la scheda non compare invece di rompere la pagina.
 */
class CoreDocumentsRelationManager extends DocumentsRelationManager
{
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        try {
            return parent::canViewForRecord($ownerRecord, $pageClass);
        } catch (QueryException) {
            return false;
        }
    }

    public function isReadOnly(): bool
    {
        try {
            return parent::isReadOnly();
        } catch (QueryException) {
            return true;
        }
    }
}
