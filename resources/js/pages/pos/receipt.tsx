import { Head, Link } from '@inertiajs/react';
import { useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/decimal';
import { home } from '@/routes/pos';
import { pdf, receipt as receiptRoute } from '@/routes/pos/sales';

type Receipt = {
    company: { name: string; document: string | null };
    header: string | null;
    footer: string | null;
    notice: string;
    is_copy: boolean;
    number: number;
    date: string | null;
    terminal: string;
    session: number;
    operator: string;
    customer: { name: string | null; document: string | null } | null;
    items: {
        description: string;
        quantity: string;
        unit: string;
        unit_price: string;
        discount: string;
        total: string;
    }[];
    items_total: string;
    discount: string;
    total: string;
    payments: {
        method: string;
        amount: string;
        tendered: string | null;
        is_received: boolean;
        due_date: string | null;
        is_informed: boolean;
    }[];
    change: string;
    refunded: string;
};

/** Comprovante não fiscal para impressão pelo navegador, sem agentes locais: bobina de 58 ou 80 mm. */
export default function PosReceipt({
    receipt,
    width,
    saleId,
    autoPrint,
}: {
    receipt: Receipt;
    width: 58 | 80;
    saleId: number;
    autoPrint: boolean;
}) {
    useEffect(() => {
        if (autoPrint) {
            const timer = window.setTimeout(() => window.print(), 300);

            return () => window.clearTimeout(timer);
        }
    }, [autoPrint]);

    return (
        <>
            <Head title={`Comprovante ${receipt.number}`} />
            <style>{`@page { size: ${width}mm auto; margin: 2mm; } @media print { .no-print { display: none !important; } body { background: #fff !important; } }`}</style>
            <div className="no-print flex flex-wrap items-center justify-center gap-2 border-b bg-muted/40 p-3">
                <Button size="sm" onClick={() => window.print()}>
                    Imprimir
                </Button>
                <Button size="sm" variant="outline" asChild>
                    <Link
                        href={receiptRoute.url(saleId, {
                            query: { width: width === 80 ? 58 : 80 },
                        })}
                    >
                        Ver em {width === 80 ? 58 : 80} mm
                    </Link>
                </Button>
                <Button size="sm" variant="outline" asChild>
                    <a href={pdf.url(saleId)} target="_blank" rel="noopener">
                        PDF
                    </a>
                </Button>
                <Button size="sm" variant="ghost" asChild>
                    <Link href={home()}>Voltar ao PDV</Link>
                </Button>
            </div>
            <article
                className="mx-auto bg-white p-2 font-mono text-black"
                style={{
                    width: `${width}mm`,
                    fontSize: width === 58 ? '10px' : '12px',
                }}
            >
                <header className="text-center">
                    <p className="font-bold">{receipt.company.name}</p>
                    {receipt.company.document && (
                        <p>CNPJ/CPF {receipt.company.document}</p>
                    )}
                    {receipt.header && (
                        <p className="whitespace-pre-line">{receipt.header}</p>
                    )}
                </header>
                <hr className="my-1 border-dashed border-black" />
                <p className="text-center font-bold">
                    {receipt.is_copy
                        ? 'COMPROVANTE — 2ª VIA'
                        : 'COMPROVANTE DE VENDA'}
                </p>
                <p>
                    Venda {receipt.number} · {receipt.date}
                </p>
                <p>
                    {receipt.terminal} · sessão {receipt.session}
                </p>
                <p>Operador: {receipt.operator}</p>
                {receipt.customer && (
                    <p>
                        Cliente: {receipt.customer.name}
                        {receipt.customer.document &&
                            ` · ${receipt.customer.document}`}
                    </p>
                )}
                <hr className="my-1 border-dashed border-black" />
                <ul>
                    {receipt.items.map((item, index) => (
                        <li key={index} className="mb-1">
                            <p>{item.description}</p>
                            <p className="flex justify-between gap-2">
                                <span>
                                    {item.quantity} {item.unit} x{' '}
                                    {formatMoney(item.unit_price)}
                                    {item.discount !== '0.00' &&
                                        ` - ${formatMoney(item.discount)}`}
                                </span>
                                <span>{formatMoney(item.total)}</span>
                            </p>
                        </li>
                    ))}
                </ul>
                <hr className="my-1 border-dashed border-black" />
                <p className="flex justify-between">
                    <span>Subtotal</span>
                    <span>{formatMoney(receipt.items_total)}</span>
                </p>
                {receipt.discount !== '0.00' && (
                    <p className="flex justify-between">
                        <span>Desconto</span>
                        <span>-{formatMoney(receipt.discount)}</span>
                    </p>
                )}
                <p className="flex justify-between text-base font-bold">
                    <span>TOTAL</span>
                    <span>{formatMoney(receipt.total)}</span>
                </p>
                <hr className="my-1 border-dashed border-black" />
                {receipt.payments.map((payment, index) => (
                    <p key={index} className="flex justify-between gap-2">
                        <span>
                            {payment.method}
                            {!payment.is_received &&
                                ` a prazo (${payment.due_date})`}
                            {payment.is_informed && ' *'}
                        </span>
                        <span>
                            {formatMoney(payment.tendered ?? payment.amount)}
                        </span>
                    </p>
                ))}
                {receipt.change !== '0.00' && (
                    <p className="flex justify-between font-bold">
                        <span>Troco</span>
                        <span>{formatMoney(receipt.change)}</span>
                    </p>
                )}
                {receipt.payments.some((payment) => payment.is_informed) && (
                    <p className="mt-1">
                        * Pagamento informado pelo operador, sem confirmação
                        bancária.
                    </p>
                )}
                {receipt.refunded !== '0.00' && (
                    <p className="mt-1">
                        Devolvido/restituído: {formatMoney(receipt.refunded)}
                    </p>
                )}
                {receipt.footer && (
                    <p className="mt-2 text-center whitespace-pre-line">
                        {receipt.footer}
                    </p>
                )}
                <hr className="my-1 border-dashed border-black" />
                <p className="text-center font-bold">{receipt.notice}</p>
            </article>
        </>
    );
}
