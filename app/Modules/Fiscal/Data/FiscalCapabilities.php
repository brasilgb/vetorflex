<?php

namespace App\Modules\Fiscal\Data;

use App\Modules\Fiscal\Enums\DocumentType;

/**
 * Capacidades efetivamente implementadas por um provedor fiscal. A interface só oferece o que estiver declarado.
 * Contingência e carta de correção não são declaradas enquanto não houver implementação e validação.
 */
readonly class FiscalCapabilities
{
    /** @param list<DocumentType> $types */
    public function __construct(
        public array $types = [],
        public bool $query = false,
        public bool $cancel = false,
        public bool $xml = false,
        public bool $pdf = false,
        public bool $webhooks = false,
        public bool $issuerRegistration = false,
        public bool $certificateUpload = false,
        public bool $correctionLetter = false,
        public bool $contingency = false,
    ) {}

    public function supports(DocumentType $type): bool
    {
        return in_array($type, $this->types, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'types' => array_map(fn (DocumentType $type): string => $type->value, $this->types),
            'query' => $this->query,
            'cancel' => $this->cancel,
            'xml' => $this->xml,
            'pdf' => $this->pdf,
            'webhooks' => $this->webhooks,
            'issuer_registration' => $this->issuerRegistration,
            'certificate_upload' => $this->certificateUpload,
            'correction_letter' => $this->correctionLetter,
            'contingency' => $this->contingency,
        ];
    }
}
