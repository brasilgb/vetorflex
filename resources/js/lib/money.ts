import { parseDecimal } from '@/lib/decimal';

/** Valor monetário em centavos inteiros (bigint), sem ponto flutuante. Entrada vazia ou inválida vale zero. */
export function toCents(value: string): bigint {
    const match = /^(-?)(\d+)(?:\.(\d{1,2}))?$/.exec(
        parseDecimal(value) || '0',
    );

    if (match === null) {
        return 0n;
    }

    const amount = BigInt(match[2] + (match[3] ?? '').padEnd(2, '0'));

    return match[1] === '-' ? -amount : amount;
}

/** Centavos para o formato da API ("1234.56"). */
export function fromCents(value: bigint): string {
    const isNegative = value < 0n;
    const digits = (isNegative ? -value : value).toString().padStart(3, '0');

    return `${isNegative ? '-' : ''}${digits.slice(0, -2)}.${digits.slice(-2)}`;
}

/** Divide o total em parcelas mensais, com os centavos restantes na última, como no servidor. */
export function monthlySchedule(
    total: string,
    count: number,
    firstDue: string,
): { due_date: string; amount: string }[] {
    const cents = toCents(total);
    const share = cents / BigInt(count);
    const [year, month, day] = firstDue.split('-').map(Number);

    return Array.from({ length: count }, (_, index) => {
        const lastDay = new Date(Date.UTC(year, month + index, 0)).getUTCDate();
        const due = new Date(
            Date.UTC(year, month - 1 + index, Math.min(day, lastDay)),
        );

        return {
            due_date: due.toISOString().slice(0, 10),
            amount: fromCents(
                index === count - 1 ? cents - share * BigInt(count - 1) : share,
            ),
        };
    });
}
