const dateFormatter = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' });
const dateTimeFormatter = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });

export function formatDate(iso: string): string {
    return dateFormatter.format(new Date(iso));
}

export function formatDateTime(iso: string): string {
    return dateTimeFormatter.format(new Date(iso));
}

export function formatNumber(value: number): string {
    return new Intl.NumberFormat().format(value);
}

/**
 * Display helper for money strings produced by the server (e.g. "0.046125000").
 * The string is already exact; this only trims trailing zeros for readability
 * while keeping at least two decimals. It never does arithmetic on the value.
 */
export function formatUsd(amount: string | null | undefined, { exact = false } = {}): string {
    if (amount === null || amount === undefined || amount === '') return '—';

    const negative = amount.startsWith('-');
    const [whole, fraction = ''] = amount.replace('-', '').split('.');
    let decimals = exact ? fraction : fraction.replace(/0+$/, '');
    if (decimals.length < 2) decimals = decimals.padEnd(2, '0');

    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    return `${negative ? '-' : ''}$${grouped}.${decimals}`;
}
