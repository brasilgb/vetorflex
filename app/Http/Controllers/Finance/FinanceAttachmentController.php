<?php

namespace App\Http\Controllers\Finance;

use App\Modules\Core\Services\AuditService;
use App\Modules\Finance\Enums\FinancePermission;
use App\Modules\Finance\Models\FinanceAttachment;
use App\Modules\Finance\Models\FinanceTitle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Anexos de títulos (comprovantes, notas, boletos recebidos). Armazenados em disco privado e entregues só
 * a quem pode consultar o título. Anexos não são excluídos: fazem parte do histórico.
 */
class FinanceAttachmentController extends FinanceController
{
    public function __construct(private AuditService $audit) {}

    public function store(Request $request, FinanceTitle $title): RedirectResponse
    {
        $this->authorizeFinance($request, FinancePermission::manageFor($title->kind));
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:'.implode(',', config('finance.attachments.mimes')), 'max:'.config('finance.attachments.maximum_kilobytes')],
        ], [
            'file.required' => 'Selecione um arquivo.',
            'file.mimes' => 'Formato não aceito. Envie PDF, imagem, XML ou texto.',
            'file.max' => 'O arquivo excede o tamanho máximo permitido.',
        ]);
        $file = $validated['file'];
        $path = $file->store("finance/{$title->company_id}/{$title->id}", config('finance.attachments.disk'));
        $attachment = $title->attachments()->create([
            'company_id' => $title->company_id,
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
        ]);
        $this->audit->record('finance.attachment_added', $this->company($request), $request->user(), $attachment, ['title' => $title->number]);
        $this->toast('Anexo enviado.');

        return back();
    }

    public function show(Request $request, FinanceAttachment $attachment): StreamedResponse
    {
        $title = FinanceTitle::query()->where('company_id', $attachment->company_id)->findOrFail($attachment->finance_title_id);
        $this->authorizeFinance($request, FinancePermission::viewFor($title->kind));

        return Storage::disk(config('finance.attachments.disk'))->download($attachment->path, $attachment->original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
