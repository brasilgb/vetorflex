<?php

namespace App\Modules\Fiscal\Services;

use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Finance\Enums\TitleKind;
use App\Modules\Finance\Models\FinanceTitle;
use App\Modules\Fiscal\Enums\DocumentType;
use App\Modules\Fiscal\Enums\FiscalOrigin;
use App\Modules\Parties\Models\Party;
use App\Modules\Pos\Enums\PosSaleStatus;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Enums\SaleOrderStatus;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Models\SaleOrderItem;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * Converte uma origem opcional (venda, atendimento do PDV ou conta a receber) nos dados iniciais de um documento
 * fiscal. Somente lê a origem: nada é alterado em vendas, estoque, caixa ou financeiro. Cada origem exige o módulo
 * correspondente habilitado e sempre pertence à empresa ativa.
 *
 * @phpstan-type Draft array{origin_type: string, origin_id: int, origin_label: string, receiver_party_id: int|null, receiver: array<string, mixed>|null, items: list<array<string, mixed>>, description: string|null, payment_method: string|null, suggested_type: DocumentType, notice: string|null}
 */
class FiscalOrigins
{
    public function __construct(private FiscalModule $module) {}

    /** @return Draft */
    public function resolve(Company $company, FiscalOrigin $origin, int $originId): array
    {
        return match ($origin) {
            FiscalOrigin::Sale => $this->sale($company, $originId),
            FiscalOrigin::Pos => $this->pos($company, $originId),
            FiscalOrigin::Finance => $this->title($company, $originId),
            FiscalOrigin::Manual => throw ValidationException::withMessages(['origin' => 'Documento avulso não tem origem.']),
        };
    }

    /**
     * Destinatário a partir do cadastro compartilhado de pessoas (documento, nome, contato e primeiro endereço).
     *
     * @return array<string, mixed>
     */
    public function receiverFromParty(Party $party): array
    {
        $address = $party->addresses()->orderBy('id')->first();

        return [
            'name' => $party->name,
            'document' => preg_replace('/\D+/', '', (string) $party->document) ?: null,
            'state_registration' => $party->state_registration,
            'municipal_registration' => $party->municipal_registration,
            'email' => $party->email,
            'phone' => $party->phone ?? $party->mobile,
            'address' => $address === null ? null : [
                'postal_code' => $address->postal_code,
                'street' => $address->street,
                'number' => $address->number,
                'complement' => $address->complement,
                'district' => $address->district,
                'city' => $address->city,
                'state' => $address->state,
            ],
        ];
    }

    /**
     * Linha fiscal de um produto do catálogo: copia somente o que estiver cadastrado, sem completar códigos.
     *
     * @return array<string, mixed>
     */
    public function productFiscalData(?Product $product): array
    {
        if ($product === null) {
            return [];
        }

        return array_filter([
            'ncm' => $product->ncm,
            'cest' => $product->cest,
            'origin' => $product->merchandise_origin,
            'cfop' => $product->getAttribute('cfop'),
            'icms_situation' => $product->getAttribute('icms_situation'),
            'pis_situation' => $product->getAttribute('pis_situation'),
            'cofins_situation' => $product->getAttribute('cofins_situation'),
            'service_code' => $product->getAttribute('service_code'),
            'tax_unit' => $product->getAttribute('tax_unit'),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @return Draft */
    private function sale(Company $company, int $orderId): array
    {
        $this->requireModule($company, 'sales', 'Documento de venda exige o módulo Vendas habilitado.');
        $order = SaleOrder::query()->where('company_id', $company->id)->with(['items.variant.product', 'customer'])->find($orderId)
            ?? throw ValidationException::withMessages(['origin_id' => 'Venda não encontrada.']);

        if (in_array($order->status, [SaleOrderStatus::Draft, SaleOrderStatus::Cancelled], true)) {
            throw ValidationException::withMessages(['origin_id' => 'Somente vendas confirmadas podem ter documento fiscal.']);
        }

        return $this->fromOrder($order, 'sale_order', $order->id, "Venda #{$order->number}", $order->customer);
    }

    /** @return Draft */
    private function pos(Company $company, int $saleId): array
    {
        $this->requireModule($company, 'pos', 'Documento do PDV exige o módulo PDV habilitado.');
        $sale = PosSale::query()->where('company_id', $company->id)->with(['order.items.variant.product', 'order.customer'])->find($saleId)
            ?? throw ValidationException::withMessages(['origin_id' => 'Atendimento do PDV não encontrado.']);

        if ($sale->status !== PosSaleStatus::Completed || $sale->order === null) {
            throw ValidationException::withMessages(['origin_id' => 'Somente atendimentos finalizados podem ter documento fiscal.']);
        }

        $draft = $this->fromOrder($sale->order, 'pos_sale', $sale->id, "PDV — atendimento #{$sale->id}", $sale->order->customer);
        $draft['suggested_type'] = DocumentType::Nfce;

        return $draft;
    }

    /** @return Draft */
    private function title(Company $company, int $titleId): array
    {
        $this->requireModule($company, 'finance', 'Documento de conta a receber exige o módulo Financeiro habilitado.');
        $title = FinanceTitle::query()->where('company_id', $company->id)->where('kind', TitleKind::Receivable)->find($titleId)
            ?? throw ValidationException::withMessages(['origin_id' => 'Conta a receber não encontrada.']);
        $party = $title->party_id === null ? null : Party::query()->where('company_id', $company->id)->find($title->party_id);

        return [
            'origin_type' => 'finance_title',
            'origin_id' => $title->id,
            'origin_label' => "Título #{$title->number}",
            'receiver_party_id' => $party?->id,
            'receiver' => $party === null ? ['name' => $title->party_name] : $this->receiverFromParty($party),
            'items' => [[
                'description' => (string) $title->description,
                'quantity' => '1',
                'unit_price' => CommercialMath::money($title->amount),
                'discount' => '0.00',
                'is_service' => true,
            ]],
            'description' => (string) $title->description,
            'payment_method' => null,
            'suggested_type' => DocumentType::Nfse,
            'notice' => null,
        ];
    }

    /** @return Draft */
    private function fromOrder(SaleOrder $order, string $type, int $id, string $label, ?Party $customer): array
    {
        $items = array_values($order->items->sortBy('position')->map(function (SaleOrderItem $item): array {
            $gross = BigDecimal::of($item->quantity)->multipliedBy($item->unit_price)->toScale(2, RoundingMode::HalfUp);
            // `net_total` já inclui o rateio do desconto geral, fixado na confirmação da venda.
            $net = BigDecimal::of($item->net_total ?? $item->total);
            $product = $item->variant?->product;

            return [
                'product_variant_id' => $item->product_variant_id,
                'code' => $item->sku,
                'description' => $item->description,
                'quantity' => (string) $item->quantity,
                'unit' => $item->unit_code,
                'unit_price' => CommercialMath::money($item->unit_price),
                'discount' => CommercialMath::money(BigDecimal::max(BigDecimal::zero(), $gross->minus($net))),
                'gtin' => $item->variant?->barcode,
                'is_service' => $item->is_service,
                ...$this->productFiscalData($product),
            ];
        })->all());
        $extras = BigDecimal::of($order->freight)->plus($order->other_costs);
        $allServices = $items !== [] && collect($items)->every(fn (array $item): bool => (bool) $item['is_service']);

        return [
            'origin_type' => $type,
            'origin_id' => $id,
            'origin_label' => $label,
            'receiver_party_id' => $customer?->id,
            'receiver' => $customer === null ? null : $this->receiverFromParty($customer),
            'items' => $items,
            'description' => $allServices ? collect($items)->pluck('description')->implode('; ') : null,
            'payment_method' => $order->payment_method?->value,
            'suggested_type' => $allServices ? DocumentType::Nfse : DocumentType::Nfe,
            'notice' => $extras->isPositive() ? 'Frete e outras despesas da venda ('.CommercialMath::money($extras).') não são incluídos automaticamente no documento fiscal.' : null,
        ];
    }

    private function requireModule(Company $company, string $identifier, string $message): void
    {
        if (! $this->module->isEnabled($company, $identifier)) {
            throw ValidationException::withMessages(['origin' => $message]);
        }
    }
}
