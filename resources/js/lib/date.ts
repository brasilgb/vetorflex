/** Datas sem horário chegam como "AAAA-MM-DD" e são exibidas sem conversão de fuso. */
export function formatDate(value: string | null | undefined): string {
    return value === null || value === undefined || value === ''
        ? '—'
        : value.slice(0, 10).split('-').reverse().join('/');
}
