/**
 * Format a numeric amount with South-Asian (lakh / crore) grouping and 2 decimals,
 * e.g. 1234567.5 → "12,34,567.50". This is how Pakistani users read figures.
 */
export function money(value: number | string | null | undefined): string {
    const n = typeof value === 'string' ? parseFloat(value) : (value ?? 0);
    if (!Number.isFinite(n)) {
        return '0.00';
    }
    return n.toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

/** Amount prefixed with the rupee symbol, e.g. "Rs 12,34,567.50". */
export function rupees(value: number | string | null | undefined): string {
    return `Rs ${money(value)}`;
}

/**
 * Short, human amount for tiles & summaries using local units:
 *   25000000 → "2.50 Cr", 350000 → "3.50 Lac", 45000 → "45,000".
 */
export function compactPk(value: number | string | null | undefined): string {
    const n = typeof value === 'string' ? parseFloat(value) : (value ?? 0);
    if (!Number.isFinite(n)) return '0';
    const abs = Math.abs(n);
    const sign = n < 0 ? '-' : '';
    if (abs >= 1e7) return `${sign}${(abs / 1e7).toFixed(2)} Cr`;
    if (abs >= 1e5) return `${sign}${(abs / 1e5).toFixed(2)} Lac`;
    return n.toLocaleString('en-IN', { maximumFractionDigits: 0 });
}

/**
 * Normalise a Pakistani phone number to international digits for wa.me,
 * e.g. "0300-1234567" → "923001234567". Returns '' if it can't be made sense of.
 */
export function waPhone(phone: string | null | undefined): string {
    if (!phone) return '';
    let d = String(phone).replace(/\D+/g, '');
    if (!d) return '';
    if (d.startsWith('0092')) d = d.slice(2);
    if (d.startsWith('92')) return d;
    if (d.startsWith('0')) return `92${d.slice(1)}`;
    if (d.length === 10 && d.startsWith('3')) return `92${d}`;
    return d;
}

/** Build a WhatsApp share link with a pre-filled (unsent) message. */
export function whatsappUrl(
    phone: string | null | undefined,
    text: string,
): string {
    const p = waPhone(phone);
    const body = encodeURIComponent(text);
    return p
        ? `https://wa.me/${p}?text=${body}`
        : `https://wa.me/?text=${body}`;
}

/** Format a money amount, rendering zero as an em dash for cleaner tables. */
export function moneyOrDash(value: number | string | null | undefined): string {
    const n = typeof value === 'string' ? parseFloat(value) : (value ?? 0);
    if (!n) {
        return '—';
    }
    return money(n);
}

const ONES = [
    '',
    'One',
    'Two',
    'Three',
    'Four',
    'Five',
    'Six',
    'Seven',
    'Eight',
    'Nine',
    'Ten',
    'Eleven',
    'Twelve',
    'Thirteen',
    'Fourteen',
    'Fifteen',
    'Sixteen',
    'Seventeen',
    'Eighteen',
    'Nineteen',
];
const TENS = [
    '',
    '',
    'Twenty',
    'Thirty',
    'Forty',
    'Fifty',
    'Sixty',
    'Seventy',
    'Eighty',
    'Ninety',
];

function threeDigitsToWords(n: number): string {
    let out = '';
    if (n >= 100) {
        out += `${ONES[Math.floor(n / 100)]} Hundred`;
        n %= 100;
        if (n) out += ' ';
    }
    if (n >= 20) {
        out += TENS[Math.floor(n / 10)];
        if (n % 10) out += `-${ONES[n % 10]}`;
    } else if (n > 0) {
        out += ONES[n];
    }
    return out;
}

/**
 * Render a positive amount as words for a payslip, e.g. 162967.33 →
 * "Rupees One Hundred Sixty-Two Thousand Nine Hundred Sixty-Seven and 33/100 Only".
 */
export function amountInWords(
    value: number | string | null | undefined,
    currency = 'Rupees',
): string {
    let n = typeof value === 'string' ? parseFloat(value) : (value ?? 0);
    if (!Number.isFinite(n) || n < 0) n = 0;

    const whole = Math.floor(n);
    const paisa = Math.round((n - whole) * 100);

    const scales = ['', ' Thousand', ' Million', ' Billion', ' Trillion'];
    let rupees = '';
    if (whole === 0) {
        rupees = 'Zero';
    } else {
        const groups: number[] = [];
        let rem = whole;
        while (rem > 0) {
            groups.push(rem % 1000);
            rem = Math.floor(rem / 1000);
        }
        const parts: string[] = [];
        for (let i = groups.length - 1; i >= 0; i--) {
            if (groups[i])
                parts.push(threeDigitsToWords(groups[i]) + scales[i]);
        }
        rupees = parts.join(' ');
    }

    const paisaPart =
        paisa > 0 ? ` and ${String(paisa).padStart(2, '0')}/100` : '';
    return `${currency} ${rupees}${paisaPart} Only`;
}
