<?php

namespace App\Modules\Pos\Data;

use App\Modules\Core\Commerce\PaymentMethod;

/**
 * Configurações do PDV da empresa (gravadas em `companies.settings['pos']`).
 *
 * @phpstan-type FinanceAccounts array<string, int>
 */
readonly class PosSettings
{
    /**
     * @param  list<string>  $paymentMethods  Formas aceitas no caixa.
     * @param  array<string, int>  $financeAccounts  Conta financeira por forma de pagamento (somente com o Financeiro).
     */
    public function __construct(
        public bool $allowsAnonymousCustomer = true,
        public string $maxDiscountPercent = '10.00',
        public bool $discountAboveLimitRequiresAuthorization = true,
        public bool $allowsPriceChange = false,
        public array $paymentMethods = [],
        public bool $blindClose = false,
        public bool $printReceipt = true,
        public ?string $receiptHeader = null,
        public ?string $receiptFooter = null,
        public array $financeAccounts = [],
        public ?int $cashAccountId = null,
        public ?int $treasuryAccountId = null,
    ) {}

    /** @param array<string, mixed>|null $values */
    public static function from(?array $values): self
    {
        $values ??= [];
        $methods = array_values(array_intersect($values['payment_methods'] ?? self::defaultMethods(), array_column(PaymentMethod::cases(), 'value')));

        return new self(
            allowsAnonymousCustomer: (bool) ($values['allow_anonymous'] ?? true),
            maxDiscountPercent: (string) ($values['max_discount_percent'] ?? '10.00'),
            discountAboveLimitRequiresAuthorization: (bool) ($values['discount_requires_authorization'] ?? true),
            allowsPriceChange: (bool) ($values['allow_price_change'] ?? false),
            paymentMethods: $methods === [] ? self::defaultMethods() : $methods,
            blindClose: (bool) ($values['blind_close'] ?? false),
            printReceipt: (bool) ($values['print_receipt'] ?? true),
            receiptHeader: $values['receipt_header'] ?? null,
            receiptFooter: $values['receipt_footer'] ?? null,
            financeAccounts: array_map('intval', array_filter($values['finance_accounts'] ?? [])),
            cashAccountId: isset($values['cash_account_id']) ? (int) $values['cash_account_id'] : null,
            treasuryAccountId: isset($values['treasury_account_id']) ? (int) $values['treasury_account_id'] : null,
        );
    }

    /** @return list<string> */
    public static function defaultMethods(): array
    {
        return ['cash', 'pix', 'credit_card', 'debit_card', 'cheque', 'voucher', 'bank_slip', 'other'];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'allow_anonymous' => $this->allowsAnonymousCustomer,
            'max_discount_percent' => $this->maxDiscountPercent,
            'discount_requires_authorization' => $this->discountAboveLimitRequiresAuthorization,
            'allow_price_change' => $this->allowsPriceChange,
            'payment_methods' => $this->paymentMethods,
            'blind_close' => $this->blindClose,
            'print_receipt' => $this->printReceipt,
            'receipt_header' => $this->receiptHeader,
            'receipt_footer' => $this->receiptFooter,
            'finance_accounts' => $this->financeAccounts,
            'cash_account_id' => $this->cashAccountId,
            'treasury_account_id' => $this->treasuryAccountId,
        ];
    }
}
