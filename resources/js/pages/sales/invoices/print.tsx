import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';
import { amountInWords, money } from '@/lib/format';

type Company = { name: string | null; legal_name: string | null; address: string | null; tax_no: string | null; base_currency: string };
type Customer = { code: string; name: string; legal_name: string | null; address: string | null; tax_no: string | null; email: string | null; phone: string | null };
type Line = { item: string; description: string | null; quantity: number; rate: number; amount: number };
type Invoice = {
    number: string | null; date: string; due_date: string | null; status: string;
    currency: string; fx_rate: number; subtotal: number; tax_amount: number; total: number; amount_paid: number;
    memo: string | null; customer: Customer; lines: Line[];
};

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });

export default function InvoiceDocument({ company, invoice }: { company: Company; invoice: Invoice }) {
    const cur = invoice.currency;
    const foreign = cur !== company.base_currency;
    const balance = Math.round((invoice.total - invoice.amount_paid) * 100) / 100;
    const words = amountInWords(invoice.total, cur === 'PKR' ? 'Rupees' : cur);

    return (
        <div className="min-h-screen bg-slate-100 py-8 text-slate-900 print:bg-white print:py-0">
            <Head title={`Invoice ${invoice.number ?? ''}`} />
            <style>{`@media print { @page { size: A4; margin: 14mm; } .no-print { display: none !important; } body { background: #fff; } }`}</style>

            {/* Toolbar (screen only) */}
            <div className="no-print mx-auto mb-4 flex max-w-[820px] items-center justify-between px-4">
                <Link href="/sales/invoices" className="inline-flex items-center gap-1 text-sm text-slate-600 hover:text-slate-900">
                    <ArrowLeft className="size-4" /> Back to invoices
                </Link>
                <button
                    onClick={() => window.print()}
                    className="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90"
                    style={gradient('#4f46e5', '#7c3aed')}
                >
                    <Printer className="size-4" /> Print / Save PDF
                </button>
            </div>

            {/* A4 document */}
            <div className="mx-auto max-w-[820px] bg-white p-10 shadow-lg print:max-w-none print:p-0 print:shadow-none">
                {/* Header */}
                <div className="flex items-start justify-between border-b-2 border-slate-800 pb-5">
                    <div>
                        <div className="text-2xl font-bold tracking-tight">{company.name ?? 'Company'}</div>
                        {company.legal_name && <div className="text-sm text-slate-500">{company.legal_name}</div>}
                        {company.address && <div className="mt-1 max-w-xs text-xs text-slate-500">{company.address}</div>}
                        {company.tax_no && <div className="text-xs text-slate-500">NTN/STRN: {company.tax_no}</div>}
                    </div>
                    <div className="text-right">
                        <div className="text-lg font-bold uppercase tracking-widest text-indigo-700">Tax Invoice</div>
                        <div className="mt-1 font-mono text-sm text-slate-700">{invoice.number ?? '—'}</div>
                        <div className="text-xs text-slate-400">Date {invoice.date}</div>
                        {invoice.due_date && <div className="text-xs text-slate-400">Due {invoice.due_date}</div>}
                    </div>
                </div>

                {/* Bill-to + meta */}
                <div className="mt-6 flex items-start justify-between gap-6">
                    <div className="rounded-xl bg-slate-50 p-4 print:bg-slate-50">
                        <div className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Bill To</div>
                        <div className="mt-1 font-semibold">{invoice.customer.legal_name || invoice.customer.name}</div>
                        <div className="text-xs text-slate-500">{invoice.customer.code}</div>
                        {invoice.customer.address && <div className="mt-1 max-w-xs text-xs text-slate-500">{invoice.customer.address}</div>}
                        {invoice.customer.tax_no && <div className="text-xs text-slate-500">NTN/STRN: {invoice.customer.tax_no}</div>}
                        {(invoice.customer.email || invoice.customer.phone) && (
                            <div className="text-xs text-slate-500">{[invoice.customer.phone, invoice.customer.email].filter(Boolean).join(' · ')}</div>
                        )}
                    </div>
                    <div className="text-right text-sm">
                        <div className="text-[10px] uppercase tracking-wide text-slate-400">Currency</div>
                        <div className="font-semibold">{cur}</div>
                        {foreign && <div className="mt-1 text-xs text-slate-500">1 {cur} = {money(invoice.fx_rate)} {company.base_currency}</div>}
                    </div>
                </div>

                {/* Lines */}
                <div className="mt-6 overflow-hidden rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="text-white" >
                            <tr style={gradient('#312e81', '#4f46e5')} className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                <th className="w-8 text-left">#</th>
                                <th className="text-left">Description</th>
                                <th className="w-24 text-right">Qty</th>
                                <th className="w-32 text-right">Rate</th>
                                <th className="w-32 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {invoice.lines.map((l, i) => (
                                <tr key={i} className="[&>td]:px-4 [&>td]:py-2.5 align-top">
                                    <td className="text-slate-400">{i + 1}</td>
                                    <td>
                                        <div className="font-medium">{l.item}</div>
                                        {l.description && <div className="text-xs text-slate-500">{l.description}</div>}
                                    </td>
                                    <td className="text-right font-mono tabular-nums">{money(l.quantity)}</td>
                                    <td className="text-right font-mono tabular-nums">{money(l.rate)}</td>
                                    <td className="text-right font-mono tabular-nums">{money(l.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {/* Totals */}
                <div className="mt-5 flex justify-end">
                    <div className="w-72 text-sm">
                        <div className="flex justify-between py-1"><span className="text-slate-500">Subtotal</span><span className="font-mono tabular-nums">{money(invoice.subtotal)} {cur}</span></div>
                        <div className="flex justify-between py-1"><span className="text-slate-500">Tax</span><span className="font-mono tabular-nums">{money(invoice.tax_amount)} {cur}</span></div>
                        <div className="mt-1 flex justify-between border-t-2 border-slate-800 py-2 text-base font-bold"><span>Total</span><span className="font-mono tabular-nums">{money(invoice.total)} {cur}</span></div>
                        {foreign && (
                            <div className="flex justify-between py-0.5 text-xs text-slate-500"><span>≈ in {company.base_currency}</span><span className="font-mono tabular-nums">{money(invoice.total * invoice.fx_rate)}</span></div>
                        )}
                        {invoice.amount_paid > 0 && (
                            <>
                                <div className="flex justify-between py-1"><span className="text-slate-500">Paid</span><span className="font-mono tabular-nums">{money(invoice.amount_paid)} {cur}</span></div>
                                <div className="flex justify-between py-1 font-semibold"><span>Balance due</span><span className="font-mono tabular-nums">{money(balance)} {cur}</span></div>
                            </>
                        )}
                    </div>
                </div>

                {/* Amount in words */}
                <div className="mt-4 rounded-lg bg-slate-50 px-4 py-2 text-xs text-slate-600 print:bg-slate-50">
                    <span className="font-semibold">Amount in words:</span> {words}
                </div>

                {invoice.memo && (
                    <div className="mt-4 text-xs text-slate-500">
                        <div className="font-semibold uppercase tracking-wide text-slate-400">Notes</div>
                        <div className="mt-0.5">{invoice.memo}</div>
                    </div>
                )}

                {/* Footer */}
                <div className="mt-10 flex items-end justify-between border-t pt-4 text-xs text-slate-400">
                    <div>Status: <span className="font-medium capitalize text-slate-600">{invoice.status}</span></div>
                    <div className="text-right">
                        <div className="mb-6 border-b border-slate-300 pb-6" />
                        Authorised Signature
                    </div>
                </div>
                <p className="mt-4 text-center text-[10px] text-slate-400">This is a computer-generated invoice.</p>
            </div>
        </div>
    );
}
