/**
 * Valores decimais trafegam como texto e são calculados com inteiros, sem ponto flutuante binário.
 */
const SCALE = 4;

/** Converte a digitação brasileira ("1.234,56") para o formato da API ("1234.56"). */
export function parseDecimal(value: string): string {
    const trimmed = value.trim();

    return trimmed.includes(',')
        ? trimmed.replaceAll('.', '').replace(',', '.')
        : trimmed;
}

function split(
    value: string,
    minimumDigits: number,
): { sign: string; integer: string; fraction: string } {
    const sign = value.startsWith('-') ? '-' : '';
    const [integer, fraction = ''] = value.replace('-', '').split('.');

    return {
        sign,
        integer,
        fraction: fraction.replace(/0+$/, '').padEnd(minimumDigits, '0'),
    };
}

export function formatDecimal(value: string, minimumDigits = 0): string {
    const { sign, integer, fraction } = split(value, minimumDigits);
    const grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    return `${sign}${grouped}${fraction ? `,${fraction}` : ''}`;
}

/** Texto inicial de um campo editável, sem separador de milhar. */
export function toDecimalInput(
    value: string | null | undefined,
    minimumDigits = 0,
): string {
    if (value === null || value === undefined) {
        return '';
    }

    const { sign, integer, fraction } = split(value, minimumDigits);

    return `${sign}${integer}${fraction ? `,${fraction}` : ''}`;
}

export function formatMoney(value: string | null | undefined): string {
    return value === null || value === undefined
        ? '—'
        : `R$ ${formatDecimal(value, 2)}`;
}

export function formatPercentage(value: string | null | undefined): string {
    return value === null || value === undefined
        ? '—'
        : `${formatDecimal(value, 2)}%`;
}

function toScaledInteger(value: string): bigint | null {
    const match = /^(\d+)(?:\.(\d{1,4}))?$/.exec(parseDecimal(value));

    return match === null
        ? null
        : BigInt(match[1] + (match[2] ?? '').padEnd(SCALE, '0'));
}

function ratio(numerator: bigint, denominator: bigint): string {
    const isNegative = numerator < 0n;
    const scaled = (isNegative ? -numerator : numerator) * 10000n;
    const rounded = (scaled * 2n + denominator) / (denominator * 2n);
    const digits = rounded.toString().padStart(3, '0');

    return `${isNegative && rounded > 0n ? '-' : ''}${digits.slice(0, -2)}.${digits.slice(-2)}`;
}

/** Margem é o lucro sobre a venda; markup é o lucro sobre o custo. */
export function profitability(
    cost: string,
    sale: string,
    promotional: string,
): { margin: string | null; markup: string | null } {
    const costValue = toScaledInteger(cost);
    const saleValue = toScaledInteger(
        promotional.trim() === '' ? sale : promotional,
    );

    if (costValue === null || saleValue === null) {
        return { margin: null, markup: null };
    }

    const profit = saleValue - costValue;

    return {
        margin: saleValue === 0n ? null : ratio(profit, saleValue),
        markup: costValue === 0n ? null : ratio(profit, costValue),
    };
}
