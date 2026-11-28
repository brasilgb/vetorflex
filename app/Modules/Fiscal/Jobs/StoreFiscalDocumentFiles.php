<?php

namespace App\Modules\Fiscal\Jobs;

use App\Modules\Fiscal\Models\FiscalDocument;
use App\Modules\Fiscal\Services\FiscalFiles;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Guarda XML e PDF do documento autorizado ou cancelado no disco privado. Reaproveitado do VetorOS
 * (`StoreFiscalDocumentFiles`): retentativas espaçadas, pois o arquivo pode demorar a ficar disponível no provedor.
 */
class StoreFiscalDocumentFiles implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public int $documentId) {}

    public function handle(FiscalFiles $files): void
    {
        $document = FiscalDocument::query()->find($this->documentId);

        if ($document !== null && $document->hasFiles()) {
            $files->store($document);
        }
    }
}
