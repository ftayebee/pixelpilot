/**
 * Compares two non-negative-or-negative decimal strings exactly, without
 * converting to floating point. Returns -1, 0 or 1.
 */
export function compareDecimal(a: string, b: string): number {
    const parse = (value: string) => {
        const negative = value.startsWith('-');
        const [whole, fraction = ''] = value.replace('-', '').split('.');
        return { negative, whole: whole.replace(/^0+(?=\d)/, ''), fraction };
    };

    const x = parse(a);
    const y = parse(b);
    const scale = Math.max(x.fraction.length, y.fraction.length);
    const toBig = (p: ReturnType<typeof parse>) => BigInt((p.negative ? '-' : '') + p.whole + p.fraction.padEnd(scale, '0'));

    const diff = toBig(x) - toBig(y);
    return diff === 0n ? 0 : diff > 0n ? 1 : -1;
}
