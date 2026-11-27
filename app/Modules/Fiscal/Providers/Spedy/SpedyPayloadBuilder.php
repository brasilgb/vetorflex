<?php

namespace App\Modules\Fiscal\Providers\Spedy;

use App\Modules\Fiscal\Enums\DocumentType;
use App\Modules\Fiscal\Models\FiscalDocument;
use App\Modules\Fiscal\Models\FiscalSetting;
use App\Modules\Fiscal\Providers\FiscalValidationException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Str;

/**
 * Traduz o documento fiscal do VetorFlex para os DTOs da API da Spedy, no modo "nota completa" (a tributação vai no
 * payload). Reaproveitado do VetorOS (`SpedyPayloadBuilder`, em produção) e adaptado ao documento do VetorFlex, que
 * já guarda destinatário e itens com seus dados fiscais. Mantidos do original: limites de campo comprovados, nenhum
 * valor tributário presumido (faltando dado, a lista do que corrigir), CSTs sem destaque suportados no Regime Normal,
 * trio tributável espelhado, CFOP interestadual (5xxx → 6xxx) e rateio de desconto. Valores calculados com
 * `Brick\Math`; convertidos para número só na fronteira JSON.
 */
class SpedyPayloadBuilder
{
    public const MAX_LEGAL_NAME = 80;

    public const MAX_NFE_RECEIVER_NAME = 60;

    public const MAX_NFE_ITEM_DESCRIPTION = 120;

    public const TAX_REGIMES = ['simplesNacional', 'simplesNacionalExcessoSublimite', 'regimeNormal', 'simplesNacionalMEI'];

    public const TAXATION_TYPES = ['taxationInMunicipality', 'taxationOutsideMunicipality', 'exemption', 'immune', 'suspendedByCourt', 'suspendedByAdministrativeProcedure', 'exportation', 'nonIncidence'];

    /** CSTs de ICMS (Regime Normal) sem destaque de imposto: os únicos suportados sem base e alíquota. */
    private const ICMS_CST_WITHOUT_TAX = [40, 41, 50, 60];

    /** CSTs de PIS/COFINS que exigem base e alíquota, não suportadas na emissão automática. */
    private const PIS_COFINS_CST_WITH_RATE = [1, 2, 3];

    /** Somente os códigos usados em produção pelo VetorOS; os demais métodos seguem como `other`. */
    private const PAYMENT_METHODS = ['pix' => 'pix', 'credit_card' => 'creditCard', 'cash' => 'money', 'transfer' => 'bankTransfer', 'bank_slip' => 'billetBanking'];

    /** @return array<string, mixed> */
    public function company(FiscalSetting $setting): array
    {
        $problems = [];
        $cnpj = $this->digits($setting->cnpj);
        $legalName = trim((string) $setting->legal_name);
        $tradeName = trim((string) ($setting->trade_name ?: $legalName));
        $address = $this->address($setting->address ?? [], $setting->city_code);

        if (strlen($cnpj) !== 14) {
            $problems[] = 'Informe o CNPJ da empresa (14 dígitos).';
        }

        if ($legalName === '') {
            $problems[] = 'Informe a razão social.';
        } elseif (mb_strlen($legalName) > self::MAX_LEGAL_NAME) {
            $problems[] = sprintf('A razão social tem %d caracteres; o emissor aceita até %d. Use a forma abreviada do cadastro na Receita.', mb_strlen($legalName), self::MAX_LEGAL_NAME);
        }

        if (mb_strlen($tradeName) > self::MAX_LEGAL_NAME) {
            $problems[] = sprintf('O nome fantasia passa de %d caracteres.', self::MAX_LEGAL_NAME);
        }

        foreach (['street' => 'logradouro', 'number' => 'número', 'district' => 'bairro', 'postalCode' => 'CEP'] as $key => $label) {
            if (blank($address[$key] ?? null)) {
                $problems[] = "Informe o {$label} do endereço fiscal.";
            }
        }

        if (! in_array($setting->tax_regime, self::TAX_REGIMES, true)) {
            $problems[] = 'Selecione o regime tributário.';
        }

        $this->guard($problems);

        return array_filter([
            'name' => $tradeName,
            'legalName' => $legalName,
            'federalTaxNumber' => $cnpj,
            'stateTaxNumber' => $this->digits($setting->state_registration) ?: null,
            'cityTaxNumber' => $this->digits($setting->municipal_registration) ?: null,
            'email' => $setting->email ?: null,
            'phone' => $this->digits($setting->phone) ?: null,
            'address' => $address,
            'taxRegime' => $setting->tax_regime,
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    public function companySettings(FiscalSetting $setting): array
    {
        $production = $setting->isProduction();
        $sefaz = $production ? 'production' : 'development';

        return [
            'general' => ['allowDuplicateFederalTaxNumbers' => true, 'allowMultipleInvoiceModelsPerOrder' => false, 'decimalPrecision' => 2],
            'productInvoice' => ['series' => $setting->seriesFor(DocumentType::Nfe), 'environmentType' => $sefaz],
            // NFS-e em homologação: Simulação da Spedy, pois a maioria das prefeituras não oferece homologação funcional.
            'serviceInvoice' => ['series' => $setting->seriesFor(DocumentType::Nfse), 'environmentType' => $production ? 'production' : 'simulation', 'issueType' => ($setting->nfse['issue_type'] ?? 'annfs') === 'normal' ? 'normal' : 'annfs'],
            'consumerInvoice' => array_filter(['series' => $setting->seriesFor(DocumentType::Nfce), 'environmentType' => $sefaz, 'tokenId' => $setting->nfce_csc_id ?: null, 'csc' => $setting->nfce_csc ?: null]),
        ];
    }

    /** @return array<string, mixed> */
    public function invoice(FiscalDocument $document, FiscalSetting $setting): array
    {
        return match ($document->type) {
            DocumentType::Nfse => $this->service($document, $setting),
            DocumentType::Nfe => $this->product($document, $setting),
            DocumentType::Nfce => $this->consumer($document, $setting),
        };
    }

    /** @return array<string, mixed> */
    private function service(FiscalDocument $document, FiscalSetting $setting): array
    {
        $tax = [...($setting->nfse ?? []), ...array_filter($document->tax_data ?? [], fn (mixed $value): bool => $value !== null && $value !== '')];
        $receiver = $document->receiver ?? [];
        $problems = [];
        $amount = BigDecimal::of($document->items_total);
        $discount = BigDecimal::of($document->discount_total);
        $description = trim((string) ($document->description ?: collect($document->items)->pluck('description')->filter()->implode('; ')));
        $serviceCode = trim((string) ($tax['service_code'] ?? ''));
        $issRate = $tax['iss_rate'] ?? null;

        if (! $amount->isPositive()) {
            $problems[] = 'Informe o valor do serviço.';
        }

        if ($discount->isPositive() && $discount->isGreaterThanOrEqualTo($amount)) {
            $problems[] = 'O desconto zera o valor da NFS-e.';
        }

        if (! in_array(strlen($this->digits($receiver['document'] ?? null)), [11, 14], true)) {
            $problems[] = 'Informe o CPF ou CNPJ do tomador do serviço.';
        }

        if (blank($receiver['name'] ?? null)) {
            $problems[] = 'Informe o nome do tomador do serviço.';
        }

        if ($description === '') {
            $problems[] = 'Descreva o serviço prestado.';
        }

        if ($serviceCode === '') {
            $problems[] = 'Informe o item da lista de serviços (LC 116) nas configurações fiscais ou no serviço.';
        }

        if ($issRate === null || $issRate === '') {
            $problems[] = 'Informe a alíquota de ISS nas configurações fiscais.';
        } elseif (BigDecimal::of((string) $issRate)->isNegative() || BigDecimal::of((string) $issRate)->isGreaterThan(5)) {
            $problems[] = 'A alíquota de ISS deve ficar entre 0% e 5% (LC 116, art. 8º).';
        }

        if (! in_array($tax['taxation_type'] ?? null, self::TAXATION_TYPES, true)) {
            $problems[] = 'Informe o tipo de tributação da NFS-e nas configurações fiscais.';
        }

        $this->guard($problems);

        $payload = [
            'integrationId' => $document->integration_id,
            'description' => Str::limit($description, 2000, ''),
            'federalServiceCode' => $serviceCode,
            'taxationType' => $tax['taxation_type'],
            'sendEmailToCustomer' => false,
            'receiver' => $this->receiver($receiver, withAddress: filled($receiver['address']['postal_code'] ?? null) && filled($receiver['address']['street'] ?? null)),
            'total' => array_filter([
                'invoiceAmount' => $this->number($amount),
                'discountUnconditionedAmount' => $discount->isPositive() ? $this->number($discount) : null,
                'issRate' => $this->number(BigDecimal::of((string) $issRate)),
            ], fn (mixed $value): bool => $value !== null),
        ];

        if (filled($document->notes)) {
            $payload['additionalInformation'] = Str::limit((string) $document->notes, 5000, '');
        }

        $cityCode = $this->digits((string) ($tax['city_code'] ?? $setting->city_code));

        if ($cityCode !== '') {
            $payload['location'] = ['code' => (int) $cityCode];
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function product(FiscalDocument $document, FiscalSetting $setting): array
    {
        $receiver = $document->receiver ?? [];
        $problems = [];
        $documentNumber = $this->digits($receiver['document'] ?? null);

        if (! in_array(strlen($documentNumber), [11, 14], true)) {
            $problems[] = 'A NF-e exige destinatário com CPF ou CNPJ. Para consumidor final no balcão, emita NFC-e.';
        }

        if (mb_strlen(trim((string) ($receiver['name'] ?? ''))) === 0) {
            $problems[] = 'Informe o nome do destinatário.';
        } elseif (mb_strlen(trim((string) $receiver['name'])) > self::MAX_NFE_RECEIVER_NAME) {
            $problems[] = sprintf('O nome do destinatário passa de %d caracteres (layout da SEFAZ). Abrevie no cadastro.', self::MAX_NFE_RECEIVER_NAME);
        }

        foreach (['postal_code' => 'CEP', 'state' => 'UF', 'city' => 'cidade', 'district' => 'bairro', 'street' => 'logradouro', 'number' => 'número'] as $field => $label) {
            if (blank($receiver['address'][$field] ?? null)) {
                $problems[] = "Informe o {$label} do endereço do destinatário.";
            }
        }

        $issuerState = Str::upper(trim((string) ($setting->address['state'] ?? '')));
        $receiverState = Str::upper(trim((string) ($receiver['address']['state'] ?? '')));
        $interstate = $issuerState !== '' && $receiverState !== '' && $issuerState !== $receiverState;
        $items = $this->productItems($document, $setting, $problems, $interstate);
        $this->guard($problems);

        return [
            'integrationId' => $document->integration_id,
            'operationNature' => 'Venda de mercadoria',
            'operationType' => 'outgoing',
            'purposeType' => 'normal',
            'destination' => $interstate ? 'interstate' : 'internal',
            'presenceType' => 'presence',
            'isFinalCustomer' => strlen($documentNumber) !== 14,
            'sendEmailToCustomer' => false,
            'receiver' => $this->receiver($receiver, withAddress: true),
            'payments' => $this->payments($document),
            'items' => $items,
        ];
    }

    /** @return array<string, mixed> */
    private function consumer(FiscalDocument $document, FiscalSetting $setting): array
    {
        $problems = [];

        if (blank($setting->nfce_csc_id) || blank($setting->nfce_csc)) {
            $problems[] = 'Informe o ID e o CSC da NFC-e nas configurações fiscais.';
        }

        $items = $this->productItems($document, $setting, $problems, false);
        $this->guard($problems);
        $payload = [
            'integrationId' => $document->integration_id,
            'operationNature' => 'Venda de mercadoria',
            'presenceType' => 'presence',
            'isFinalCustomer' => true,
            'payments' => $this->payments($document),
            'items' => $items,
        ];
        $receiver = $document->receiver ?? [];

        // Consumidor identificado só com CPF/CNPJ válido; sem ele, NFC-e sem destinatário.
        if (in_array(strlen($this->digits($receiver['document'] ?? null)), [11, 14], true)) {
            $payload['receiver'] = array_filter(['name' => $receiver['name'] ?? null, 'federalTaxNumber' => $this->digits($receiver['document'])]);
        }

        return $payload;
    }

    /**
     * Itens da NF-e/NFC-e: NCM e CFOP de cada item, ICMS/PIS/COFINS e unidade do item ou dos padrões confirmados pela
     * empresa. Faltando algo, a emissão é bloqueada com a lista do que corrigir.
     *
     * @param  list<string>  $problems
     * @return list<array<string, mixed>>
     */
    private function productItems(FiscalDocument $document, FiscalSetting $setting, array &$problems, bool $interstate): array
    {
        $defaults = $setting->product_defaults ?? [];
        $simples = in_array($setting->tax_regime, ['simplesNacional', 'simplesNacionalExcessoSublimite', 'simplesNacionalMEI'], true);
        $items = [];

        if ($document->items === []) {
            $problems[] = 'Adicione ao menos uma mercadoria.';
        }

        foreach ($document->items as $index => $item) {
            $line = $index + 1;
            $name = trim((string) ($item['description'] ?? ''));
            $ncm = $this->digits($item['ncm'] ?? null);
            $cfop = $this->digits(($item['cfop'] ?? null) ?: ($defaults['cfop'] ?? null));
            $unit = trim((string) (($item['unit'] ?? null) ?: ($defaults['unit'] ?? '')));
            $origin = (string) (($item['origin'] ?? null) ?? ($defaults['origin'] ?? ''));
            $icms = trim((string) (($item['icms_situation'] ?? null) ?: ($defaults['icms_situation'] ?? '')));
            $pis = trim((string) (($item['pis_situation'] ?? null) ?: ($defaults['pis_situation'] ?? '')));
            $cofins = trim((string) (($item['cofins_situation'] ?? null) ?: ($defaults['cofins_situation'] ?? '')));
            $quantity = BigDecimal::of((string) ($item['quantity'] ?? '0'));
            $unitAmount = BigDecimal::of((string) ($item['unit_price'] ?? '0'))->toScale(2, RoundingMode::HalfUp);
            $discount = BigDecimal::of((string) ($item['discount'] ?? '0'))->toScale(2, RoundingMode::HalfUp);

            if ($name === '') {
                $problems[] = "Descreva a mercadoria da linha {$line}.";
            } elseif (mb_strlen($name) > self::MAX_NFE_ITEM_DESCRIPTION) {
                $problems[] = sprintf('A descrição da linha %d passa de %d caracteres (limite da NF-e/NFC-e).', $line, self::MAX_NFE_ITEM_DESCRIPTION);
            }

            if (strlen($ncm) !== 8) {
                $problems[] = "Informe o NCM (8 dígitos) de \"{$name}\".";
            }

            if (strlen($cfop) !== 4) {
                $problems[] = "Informe o CFOP (4 dígitos) de \"{$name}\".";
            }

            foreach (['unidade comercial' => $unit, 'origem da mercadoria' => $origin, $simples ? 'CSOSN' : 'CST do ICMS' => $icms, 'CST do PIS' => $pis, 'CST do COFINS' => $cofins] as $label => $value) {
                if ($value === '') {
                    $problems[] = "Informe a {$label} de \"{$name}\" ou nos padrões fiscais.";
                }
            }

            if (! $simples && $icms !== '' && ! in_array((int) $icms, self::ICMS_CST_WITHOUT_TAX, true)) {
                $problems[] = "CST de ICMS {$icms} exige base e alíquota, ainda não suportadas na emissão (use 40, 41, 50 ou 60).";
            }

            if (! $simples && (in_array((int) $pis, self::PIS_COFINS_CST_WITH_RATE, true) || in_array((int) $cofins, self::PIS_COFINS_CST_WITH_RATE, true))) {
                $problems[] = 'CST de PIS/COFINS com alíquota ainda não é suportado na emissão.';
            }

            if (! $quantity->isPositive() || ! $unitAmount->isPositive()) {
                $problems[] = "Informe quantidade e valor unitário maiores que zero na linha {$line}.";
            }

            if ($interstate && str_starts_with($cfop, '5')) {
                $cfop = '6'.substr($cfop, 1);
            }

            $taxes = ['icms' => ['origin' => (int) $origin, $simples ? 'csosn' : 'cst' => (int) $icms], 'pis' => ['cst' => (int) $pis], 'cofins' => ['cst' => (int) $cofins]];
            $items[] = array_filter([
                'code' => (string) (($item['code'] ?? null) ?: $line),
                'description' => $name,
                'ncm' => $ncm,
                'cest' => $this->digits($item['cest'] ?? null) ?: null,
                'gtin' => $this->digits($item['gtin'] ?? null) ?: null,
                'cfop' => (int) $cfop,
                'unit' => $unit,
                'quantity' => $this->number($quantity, 4),
                'unitAmount' => $this->number($unitAmount),
                'totalAmount' => $this->number($quantity->multipliedBy($unitAmount)->toScale(2, RoundingMode::HalfUp)),
                'discountAmount' => $discount->isPositive() ? $this->number($discount) : null,
                // Trio tributável espelhado: sem ele a SEFAZ rejeita (630) ou a Spedy recusa (SPD003).
                'unitTax' => trim((string) (($item['tax_unit'] ?? null) ?: ($defaults['tax_unit'] ?? ''))) ?: $unit,
                'quantityTax' => $this->number($quantity, 4),
                'unitTaxAmount' => $this->number($unitAmount),
                'taxes' => $taxes,
            ], fn (mixed $value): bool => $value !== null);
        }

        return $items;
    }

    /** @return list<array{method: string, amount: float}> */
    private function payments(FiscalDocument $document): array
    {
        return [['method' => self::PAYMENT_METHODS[$document->payment_method ?? ''] ?? 'other', 'amount' => $this->number(BigDecimal::of($document->total))]];
    }

    /**
     * @param  array<string, mixed>  $receiver
     * @return array<string, mixed>
     */
    private function receiver(array $receiver, bool $withAddress): array
    {
        $data = array_filter([
            'name' => trim((string) ($receiver['name'] ?? '')),
            'federalTaxNumber' => $this->digits($receiver['document'] ?? null),
            'email' => ($receiver['email'] ?? null) ?: null,
            'phoneNumber' => $this->digits($receiver['phone'] ?? null) ?: null,
        ]);

        if ($withAddress) {
            $data['address'] = $this->address((array) ($receiver['address'] ?? []), $receiver['address']['city_code'] ?? null);
        }

        return $data;
    }

    /**
     * Endereço no formato da Spedy: cidade pelo código IBGE quando houver, senão por nome e UF (minúscula).
     *
     * @param  array<string, mixed>  $address
     * @return array<string, mixed>
     */
    private function address(array $address, ?string $cityCode): array
    {
        $code = $this->digits($cityCode);

        return array_filter([
            'street' => Str::limit(trim((string) ($address['street'] ?? '')), 100, ''),
            'number' => Str::limit(trim((string) (($address['number'] ?? '') ?: 'S/N')), 10, ''),
            'district' => Str::limit(trim((string) ($address['district'] ?? '')), 100, ''),
            'postalCode' => $this->digits($address['postal_code'] ?? null),
            'additionalInformation' => filled($address['complement'] ?? null) ? Str::limit((string) $address['complement'], 150, '') : null,
            'city' => strlen($code) === 7 ? ['code' => $code] : array_filter(['name' => $address['city'] ?? null, 'state' => Str::lower((string) ($address['state'] ?? '')) ?: null]),
        ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /** Fronteira JSON: a API recebe números; o cálculo anterior é decimal exato. */
    /** @param int<0, max> $scale */
    private function number(BigDecimal $value, int $scale = 2): float
    {
        return (float) (string) $value->toScale($scale, RoundingMode::HalfUp);
    }

    private function digits(mixed $value): string
    {
        return (string) preg_replace('/\D+/', '', (string) $value);
    }

    /** @param list<string> $problems */
    private function guard(array $problems): void
    {
        if ($problems !== []) {
            throw FiscalValidationException::problems(array_values(array_unique($problems)));
        }
    }
}
