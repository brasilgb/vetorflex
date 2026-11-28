<?php

namespace App\Http\Controllers\RootAdmin;

use App\Http\Controllers\Controller;
use App\Modules\Commercial\Models\ContactRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Pedidos de contato comercial recebidos pela página pública. */
class ContactRequestController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('root-admin/contacts', [
            'contacts' => ContactRequest::query()->latest('id')->paginate(30)->through(fn (ContactRequest $contact): array => [
                ...$contact->only(['id', 'name', 'email', 'phone', 'company_name', 'message', 'modules']),
                'created_at' => $contact->created_at?->toIso8601String(),
                'handled_at' => $contact->handled_at?->toIso8601String(),
            ]),
        ]);
    }

    public function update(Request $request, ContactRequest $contact): RedirectResponse
    {
        $validated = $request->validate(['handled' => ['required', 'boolean']]);
        $contact->update(['handled_at' => $validated['handled'] ? now() : null]);
        Inertia::flash('toast', ['type' => 'success', 'message' => $validated['handled'] ? 'Contato marcado como atendido.' : 'Contato reaberto.']);

        return back();
    }
}
