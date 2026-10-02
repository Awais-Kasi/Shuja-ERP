/** Format a numeric amount with thousands separators and 2 decimals. */
export function money(value: number | string | null | undefined): string {
    const n = typeof value === 'string' ? parseFloat(value) : (value ?? 0);
    if (!Number.isFinite(n)) {
        return '0.00';
    }
    return n.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

/** Format a money amount, rendering zero as an em dash for cleaner tables. */
export function moneyOrDash(value: number | string | null | undefined): string {
    const n = typeof value === 'string' ? parseFloat(value) : (value ?? 0);
    if (!n) {
        return '—';
    }
    return money(n);
}

const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
    'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

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
export function amountInWords(value: number | string | null | undefined, currency = 'Rupees'): string {
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
            if (groups[i]) parts.push(threeDigitsToWords(groups[i]) + scales[i]);
        }
        rupees = parts.join(' ');
    }

    const paisaPart = paisa > 0 ? ` and ${String(paisa).padStart(2, '0')}/100` : '';
    return `${currency} ${rupees}${paisaPart} Only`;
}
