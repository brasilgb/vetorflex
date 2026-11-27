<?php

namespace App\Modules\Fiscal\Support;

use App\Modules\Fiscal\Enums\DocumentType;
use App\Modules\Fiscal\Models\FiscalDocument;
use App\Modules\Fiscal\Models\FiscalDocumentEvent;
use App\Modules\Fiscal\Models\FiscalSetting;

/**
 * Dados de documentos e configuração fiscal para as telas. Credenciais, CSC e certificado nunca saem daqui.
 */
class FiscalPresenter
{
    /** @return array<string, mixed> */
    public function document(FiscalDocument $document): array
    {
        return [
            'id' => $document->id,
            'local_number' => $document->local_number,
            'type' => $document->type->value,
            'type_label' => $document->type->shortLabel(),
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'origin' => $document->origin->value,
            'origin_label' => $document->origin->label(),
            'origin_type' => $document->origin_type,
            'origin_id' => $document->origin_id,
            'origin_reference' => $document->origin_label,
            'environment' => $document->environment,
            'provider' => $document->provider,
            'receiver_name' => $document->receiver['name'] ?? null,
            'receiver_document' => $document->receiver['document'] ?? null,
            'total' => (string) $document->total,
            'number' => $document->number,
            'series' => $document->series,
            'access_key' => $document->access_key,
            'issued_at' => $document->issued_at?->toIso8601String(),
            'created_at' => $document->created_at?->toIso8601String(),
            'error_message' => $document->error_message,
            'has_files' => $document->hasFiles(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(FiscalDocument $document): array
    {
        return [
            ...$this->document($document),
            'integration_id' => $document->integration_id,
            'receiver_party_id' => $document->receiver_party_id,
            'receiver' => $document->receiver,
            'items' => $document->items,
            'tax_data' => $document->tax_data,
            'description' => $document->description,
            'notes' => $document->notes,
            'payment_method' => $document->payment_method,
            'items_total' => (string) $document->items_total,
            'discount_total' => (string) $document->discount_total,
            'authorization_protocol' => $document->authorization_protocol,
            'provider_status' => $document->provider_status,
            'submitted_at' => $document->submitted_at?->toIso8601String(),
            'cancelled_at' => $document->cancelled_at?->toIso8601String(),
            'cancel_reason' => $document->cancel_reason,
            'xml_sha256' => $document->xml_sha256,
            'pdf_sha256' => $document->pdf_sha256,
            'created_by_name' => $document->created_by_name,
        ];
    }

    /** @return array<string, mixed> */
    public function event(FiscalDocumentEvent $event): array
    {
        return ['id' => $event->id, 'event' => $event->event, 'description' => $event->description, 'to_status' => $event->to_status, 'user_name' => $event->user_name, 'created_at' => $event->created_at?->toIso8601String()];
    }

    /** @return array<string, mixed> */
    public function settings(FiscalSetting $setting): array
    {
        return [
            'legal_name' => $setting->legal_name,
            'trade_name' => $setting->trade_name,
            'cnpj' => $setting->cnpj,
            'state_registration' => $setting->state_registration,
            'municipal_registration' => $setting->municipal_registration,
            'email' => $setting->email,
            'phone' => $setting->phone,
            'address' => $setting->address ?? [],
            'city_code' => $setting->city_code,
            'tax_regime' => $setting->tax_regime,
            'environment' => $setting->environment,
            'enabled_types' => $setting->enabled_types ?? [],
            'series' => $setting->series ?? [],
            'nfse' => $setting->nfse ?? [],
            'product_defaults' => $setting->product_defaults ?? [],
            'nfce_csc_id' => $setting->nfce_csc_id,
            'has_nfce_csc' => filled($setting->nfce_csc),
            'registration_status' => $setting->registration_status,
            'registration_error' => $setting->registration_error,
            'is_registered' => $setting->isRegistered(),
            'registered_at' => $setting->registered_at?->toIso8601String(),
            'certificate_subject' => $setting->certificate_subject,
            'certificate_expires_at' => $setting->certificate_expires_at?->toIso8601String(),
            'has_valid_certificate' => $setting->hasValidCertificate(),
            'tax_settings_confirmed_at' => $setting->tax_settings_confirmed_at?->toIso8601String(),
            'tax_settings_confirmed_by' => $setting->tax_settings_confirmed_by,
            'production_released_at' => $setting->production_released_at?->toIso8601String(),
        ];
    }

    /** @return list<array{value: string, label: string}> */
    public function types(): array
    {
        return array_map(fn (DocumentType $type): array => ['value' => $type->value, 'label' => $type->label()], DocumentType::cases());
    }
}
