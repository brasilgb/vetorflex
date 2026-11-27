<?php

namespace App\Modules\Fiscal\Jobs;

use App\Modules\Fiscal\Models\FiscalDocument;
use App\Modules\Fiscal\Services\FiscalDocumentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Transmissão de um documento fiscal ao provedor. Repetir é seguro: só transmite documento "Aguardando" e sempre com
 * o mesmo identificador de integração.
 */
class TransmitFiscalDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public int $documentId) {}

    public function handle(FiscalDocumentService $documents): void
    {
        $document = FiscalDocument::query()->find($this->documentId);

        if ($document !== null) {
            $documents->transmit($document);
        }
    }
}
