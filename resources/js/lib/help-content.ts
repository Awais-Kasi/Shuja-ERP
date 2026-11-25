/**
 * Plain-Urdu "Madad" (help) content for each screen, keyed by URL path prefix.
 * The Help Center picks the longest matching prefix, otherwise the general entry.
 * One place to maintain friendly, non-accountant guidance for the whole app.
 */
export type HelpStep = { text: string; note?: string };

export type HelpEntry = {
    title: string;
    intro: string;
    steps: HelpStep[];
};

const GENERAL: HelpEntry = {
    title: 'Is software ko kaise chalayein',
    intro: 'Ghabrayein nahi — rozana ke kaam bohat aasaan hain. Neeche tareeqa hai.',
    steps: [
        {
            text: 'Baayein (left) menu se apna kaam chunein — ya ghar (Dashboard) ke bade buttons dabayein.',
        },
        {
            text: 'Maal bechna ho to "Nai Bikri", khareedna ho to "Nai Khareed".',
        },
        {
            text: 'Kis ne kitna dena/lena hai dekhna ho to "Udhaar" kholein.',
        },
        {
            text: 'Upar "Dhoondo" mein kisi bhi customer, maal ya bill ka naam likh kar seedha khol sakte hain.',
        },
        {
            text: 'Kabhi samajh na aaye to har screen par yeh "Madad" button dabayein.',
            note: 'Aap abhi wohi dekh rahe hain 🙂',
        },
    ],
};

const ENTRIES: Array<{ match: string; entry: HelpEntry }> = [
    {
        match: '/dashboard',
        entry: {
            title: 'Ghar (Dashboard)',
            intro: 'Yeh aap ke poore karobar ki ek nazar hai — aur rozana ke kaam ka shortcut.',
            steps: [
                {
                    text: '"Jaldi Kaam" ke bade rangeen buttons se seedha bikri, khareed ya paisa likhein.',
                },
                {
                    text: 'Upar wale cards: aap ke paas kitna Cash aur Bank mein paisa hai, customer se kitna lena, supplier ko kitna dena.',
                },
                {
                    text: '"Maal khatam ho raha hai" — jo cheezein kam reh gayi hain woh yahan dikhti hain.',
                },
            ],
        },
    },
    {
        match: '/sales/quick',
        entry: {
            title: 'Nai Bikri (Cash Memo)',
            intro: 'Jab aap kisi ko maal bechein, yahan se ek hi screen par poori bikri likh dein.',
            steps: [
                {
                    text: 'Customer chunein. Agar naam zaroori nahi to "Cash" rehne dein.',
                },
                { text: 'Maal (item) chunein aur kitna becha woh likhein.' },
                {
                    text: 'Rate khud aa jata hai — zaroorat ho to badal dein.',
                    note: 'Rate "Items" screen par "Bikri Rate" se aata hai.',
                },
                {
                    text: 'Agar abhi paisa mil gaya hai to "Paisa mila" mein woh raqam likhein. Udhaar hai to khaali chhor dein.',
                },
                { text: 'Neeche "Bikri pakki karein" dabayein. Ho gaya!' },
            ],
        },
    },
    {
        match: '/purchase/quick',
        entry: {
            title: 'Nai Khareed (Quick Purchase)',
            intro: 'Jab aap maal khareedein, yahan se ek screen par maal + bill + payment likh dein.',
            steps: [
                { text: 'Supplier (jisse khareeda) chunein.' },
                { text: 'Maal chunein, kitna aaya aur kya rate tha woh likhein.' },
                {
                    text: 'Agar abhi paisa de diya to "Paisa diya" mein woh raqam likhein, warna khaali chhor dein (udhaar).',
                },
                { text: '"Khareed pakki karein" dabayein.' },
            ],
        },
    },
    {
        match: '/udhaar',
        entry: {
            title: 'Udhaar — Lena / Dena',
            intro: 'Ek nazar mein: kis customer se paisa lena hai, aur kis supplier ko dena hai.',
            steps: [
                {
                    text: 'Baayein taraf "Lena hai" — har customer ke saamne woh raqam jo woh aap ko deyga.',
                },
                {
                    text: 'Daayein taraf "Dena hai" — har supplier ko aap ne kitna dena hai.',
                },
                {
                    text: 'Kisi ke phone number par WhatsApp ka button daba kar yaad-dehani bhej sakte hain.',
                },
            ],
        },
    },
    {
        match: '/day-book',
        entry: {
            title: 'Aaj ka Hisaab (Roznamcha)',
            intro: 'Aaj din bhar kya hua — ek safe par.',
            steps: [
                {
                    text: 'Aaj kitni Bikri hui, kitna Kharcha, kitna Cash aaya aur gaya.',
                },
                {
                    text: 'Upar date badal kar kisi bhi din ka hisaab dekh sakte hain.',
                },
                { text: 'Neeche us din ki saari entries (roznamcha) ki list hai.' },
            ],
        },
    },
    {
        match: '/sales/receipts',
        entry: {
            title: 'Paisa Aaya (Customer se)',
            intro: 'Jab koi customer aap ko paisa de, yahan likhein.',
            steps: [
                { text: 'Customer chunein jisne paisa diya.' },
                { text: 'Kitna paisa aaya aur Cash ya Bank — woh chunein.' },
                { text: 'Save/pakka karein — us ka udhaar khud kam ho jayega.' },
            ],
        },
    },
    {
        match: '/purchase/payments',
        entry: {
            title: 'Paisa Diya (Supplier ko)',
            intro: 'Jab aap kisi supplier ko paisa dein, yahan likhein.',
            steps: [
                { text: 'Supplier chunein jise paisa diya.' },
                { text: 'Kitna diya aur Cash ya Bank se — woh chunein.' },
                { text: 'Save karein — us ka dena khud kam ho jayega.' },
            ],
        },
    },
    {
        match: '/inventory/items',
        entry: {
            title: 'Maal / Items',
            intro: 'Yahan aap apni cheezein (maal) banate aur unka rate rakhte hain.',
            steps: [
                { text: '"Add" se naya maal banayein — naam aur unit (KG, piece).' },
                {
                    text: '"Bikri Rate" = kis rate par bechte hain (bikri par khud aa jata hai). "Khareed Rate" = lagat.',
                },
                {
                    text: '"Kam ho to batao" (reorder level) likhein — maal kam hote hi dashboard par alert aa jayega.',
                },
            ],
        },
    },
    {
        match: '/sales/customers',
        entry: {
            title: 'Customers (Gahak)',
            intro: 'Aap ke customers ki list aur unke khaate.',
            steps: [
                { text: '"Add" se naya customer banayein — naam aur phone kaafi hai.' },
                {
                    text: 'Kisi customer par "Khata" se dekhein usne kab kya liya aur kitna baqaya hai.',
                },
            ],
        },
    },
    {
        match: '/purchase/suppliers',
        entry: {
            title: 'Suppliers (Jisse khareedte hain)',
            intro: 'Aap ke suppliers ki list aur unke khaate.',
            steps: [
                { text: '"Add" se naya supplier banayein — naam aur phone.' },
                {
                    text: 'Kisi supplier par "Khata" se dekhein aap ne kitna khareeda aur kitna dena baqi hai.',
                },
            ],
        },
    },
    {
        match: '/reports',
        entry: {
            title: 'Reports (Report)',
            intro: 'Karobar ki tafseeli reportein — munafa, baqaya, stock waghera.',
            steps: [
                { text: 'Apni pasand ki report chunein.' },
                {
                    text: 'Har report ko upar "Print / PDF" se chaap sakte ya PDF bana sakte hain.',
                },
            ],
        },
    },
];

/** Returns the help entry for a given URL path (longest-prefix match, else general). */
export function helpForPath(path: string): HelpEntry {
    const clean = (path.split('?')[0] || '/').replace(/\/+$/, '') || '/';
    let best: { len: number; entry: HelpEntry } | null = null;
    for (const { match, entry } of ENTRIES) {
        if (clean === match || clean.startsWith(match + '/') || clean.startsWith(match)) {
            if (!best || match.length > best.len) {
                best = { len: match.length, entry };
            }
        }
    }
    return best?.entry ?? GENERAL;
}
