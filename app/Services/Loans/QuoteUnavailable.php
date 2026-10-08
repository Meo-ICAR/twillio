<?php

namespace App\Services\Loans;

/** Il servizio di preventivazione non può dare un importo per questa richiesta (dati insufficienti, non simulabile, errore o nessuna offerta). */
class QuoteUnavailable extends \RuntimeException {}
