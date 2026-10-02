import { Head, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Wand2 } from 'lucide-react';
import { type FormEvent, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { money } from '@/lib/format';

type Party = { id: number; code: string; name: string };
type Option = { id: number; code: string; name: string };
type Doc = { id: number; party_id: number; number: string; date: string; currency: string; total: number; outstanding: number };

export default function CreatePayment({ direction, parties, accounts, openDocuments, today, baseCurrency, currencies, rates }: {
    direction: string; parties: Party[]; accounts: Option[]; openDocuments: Doc[]; today: string;
    baseCurrency: string; currencies: string[]; rates: Record<string, number>;
}) {
    const receipt = direction === 'receive';
    const base = receipt ? '/sales/receipts' : '/purchase/payments';
    const cfg = receipt
        ? { title: 'Record customer receipt', party: 'Customer', account: 'Deposit to', docs: 'Open invoices' }
        : { title: 'Record supplier payment', party: 'Supplier', account: 'Pay from', docs: 'Open bills' };

    const [alloc, setAlloc] = useState<Record<number, string>>({});
    const form = useForm({ party_id: '', account_id: '', payment_date: today, amount: '', currency: baseCurrency, fx_rate: '1', reference: '', memo: '' });

    const isForeign = form.data.currency !== baseCurrency;
    const fxRate = parseFloat(form.data.fx_rate) || 0;
    const pickCurrency = (c: string) => {
        form.setData('currency', c);
        form.setData('fx_rate', c === baseCurrency ? '1' : String(rates[c] ?? ''));
        setAlloc({});
    };

    // Only documents in the payment currency can be settled (each clears at its own booking rate).
    const docs = useMemo(
        () => openDocuments.filter((d) => String(d.party_id) === form.data.party_id && d.currency === form.data.currency),
        [openDocuments, form.data.party_id, form.data.currency],
    );
    const amount = parseFloat(form.data.amount) || 0;
    const allocated = Object.values(alloc).reduce((s, v) => s + (parseFloat(v) || 0), 0);
    const unapplied = Math.round((amount - allocated) * 10000) / 10000;

    const setParty = (v: string) => { form.setData('party_id', v); setAlloc({}); };
    const setDoc = (id: number, v: string) => setAlloc((a) => ({ ...a, [id]: v }));

    const autoAllocate = () => {
        let left = amount;
        const next: Record<number, string> = {};
        for (const d of docs) {
            if (left <= 0) break;
            const take = Math.min(left, d.outstanding);
            next[d.id] = String(Math.round(take * 100) / 100);
            left = Math.round((left - take) * 10000) / 10000;
        }
        setAlloc(next);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            allocations: Object.entries(alloc)
                .map(([id, a]) => ({ id: Number(id), amount: parseFloat(a) || 0 }))
                .filter((x) => x.amount > 0),
        }));
        form.post(base);
    };
    const postingError = (form.errors as Record<string, string>).posting;

    return (
        <>
            <Head title={cfg.title} />
            <form onSubmit={submit} className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <Button type="button" variant="ghost" size="sm" className="mb-3 -ml-2" onClick={() => router.visit(base)}><ArrowLeft className="size-4" /> Back</Button>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h1 className="text-2xl font-semibold tracking-tight">{cfg.title}</h1>
                        <Button type="submit" disabled={form.processing || !form.data.party_id || amount <= 0}>Post</Button>
                    </div>
                </div>
                {postingError && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">{postingError}</div>}

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="grid gap-1.5">
                        <Label>{cfg.party}</Label>
                        <Select value={form.data.party_id} onValueChange={setParty}>
                            <SelectTrigger><SelectValue placeholder={`Select ${cfg.party.toLowerCase()}`} /></SelectTrigger>
                            <SelectContent>{parties.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.code} — {p.name}</SelectItem>)}</SelectContent>
                        </Select>
                        {form.errors.party_id && <p className="text-destructive text-xs">{form.errors.party_id}</p>}
                    </div>
                    <div className="grid gap-1.5">
                        <Label>{cfg.account}</Label>
                        <Select value={form.data.account_id} onValueChange={(v) => form.setData('account_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Cash / bank" /></SelectTrigger>
                            <SelectContent>{accounts.map((a) => <SelectItem key={a.id} value={String(a.id)}>{a.code} — {a.name}</SelectItem>)}</SelectContent>
                        </Select>
                        {form.errors.account_id && <p className="text-destructive text-xs">{form.errors.account_id}</p>}
                    </div>
                    <div className="grid gap-1.5"><Label>Date</Label><Input type="date" value={form.data.payment_date} onChange={(e) => form.setData('payment_date', e.target.value)} />{form.errors.payment_date && <p className="text-destructive text-xs">{form.errors.payment_date}</p>}</div>
                    <div className="grid gap-1.5"><Label>Amount</Label><Input inputMode="decimal" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} className="text-right font-mono" placeholder="0.00" />{form.errors.amount && <p className="text-destructive text-xs">{form.errors.amount}</p>}</div>
                    <div className="grid gap-1.5">
                        <Label>Currency</Label>
                        <Select value={form.data.currency} onValueChange={pickCurrency}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>{currencies.map((c) => <SelectItem key={c} value={c}>{c}{c === baseCurrency ? ' · base' : ''}</SelectItem>)}</SelectContent>
                        </Select>
                    </div>
                    {isForeign ? (
                        <div className="grid gap-1.5">
                            <Label htmlFor="fx">Rate → {baseCurrency}</Label>
                            <Input id="fx" inputMode="decimal" value={form.data.fx_rate} onChange={(e) => form.setData('fx_rate', e.target.value)} className="text-right font-mono tabular-nums" placeholder="0.0000" />
                            {form.errors.fx_rate && <p className="text-destructive text-xs">{form.errors.fx_rate}</p>}
                        </div>
                    ) : <div className="hidden sm:block" />}
                    <div className="grid gap-1.5 sm:col-span-2"><Label>Reference</Label><Input value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} placeholder="Cheque / transfer no." /></div>
                    <div className="grid gap-1.5 sm:col-span-2"><Label>Memo</Label><Input value={form.data.memo} onChange={(e) => form.setData('memo', e.target.value)} placeholder="Optional" /></div>
                </div>

                <div>
                    <div className="mb-2 flex items-center justify-between">
                        <h2 className="text-sm font-semibold text-muted-foreground">{cfg.docs}</h2>
                        <Button type="button" variant="outline" size="sm" disabled={!docs.length || amount <= 0} onClick={autoAllocate}><Wand2 className="size-4" /> Auto-allocate</Button>
                    </div>
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full min-w-[640px] text-sm">
                            <thead className="bg-muted/50 text-muted-foreground">
                                <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                    <th className="text-left">Document</th>
                                    <th className="text-left">Date</th>
                                    <th className="text-right">Total</th>
                                    <th className="text-right">Outstanding</th>
                                    <th className="w-40 text-right">Allocate</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {!form.data.party_id && <tr><td colSpan={5} className="text-muted-foreground px-4 py-8 text-center">Select a {cfg.party.toLowerCase()} to see open documents.</td></tr>}
                                {form.data.party_id && docs.length === 0 && <tr><td colSpan={5} className="text-muted-foreground px-4 py-8 text-center">No open documents — the amount will sit on account.</td></tr>}
                                {docs.map((d) => (
                                    <tr key={d.id} className="[&>td]:px-4 [&>td]:py-2">
                                        <td className="font-mono text-xs">{d.number}</td>
                                        <td className="font-mono text-xs">{d.date}</td>
                                        <td className="text-right font-mono tabular-nums">{money(d.total)}</td>
                                        <td className="text-right font-mono tabular-nums">{money(d.outstanding)}</td>
                                        <td><Input inputMode="decimal" value={alloc[d.id] ?? ''} onChange={(e) => setDoc(d.id, e.target.value)} className="text-right font-mono tabular-nums" placeholder="0.00" /></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                <div className="flex flex-col items-end gap-1.5 text-sm">
                    <div className="flex w-64 justify-between"><span className="text-muted-foreground">Amount</span><span className="font-mono tabular-nums">{money(amount)} {form.data.currency}</span></div>
                    <div className="flex w-64 justify-between"><span className="text-muted-foreground">Allocated</span><span className="font-mono tabular-nums">{money(allocated)} {form.data.currency}</span></div>
                    <div className={`flex w-64 justify-between border-t pt-1.5 font-semibold ${unapplied < -0.0001 ? 'text-rose-600' : ''}`}>
                        <span>{unapplied < -0.0001 ? 'Over-allocated' : 'On account'}</span>
                        <span className="font-mono tabular-nums">{money(unapplied)} {form.data.currency}</span>
                    </div>
                    {isForeign && fxRate > 0 && (
                        <div className="flex w-64 justify-between text-xs text-muted-foreground"><span>≈ in {baseCurrency}</span><span className="font-mono tabular-nums">{money(amount * fxRate)}</span></div>
                    )}
                </div>
                {isForeign && <p className="text-muted-foreground -mt-2 text-xs">Settlement books realised FX gain/loss for the rate difference since each document was raised.</p>}
            </form>
        </>
    );
}
