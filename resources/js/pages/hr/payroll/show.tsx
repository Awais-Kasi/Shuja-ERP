import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, BookOpenText, Banknote, CheckCircle2, FileText, HandCoins, Undo2 } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { usePage } from '@inertiajs/react';
import { usePermissions } from '@/hooks/use-permissions';
import { money, moneyOrDash } from '@/lib/format';

type Payslip = {
    id: number; employee: string; department: string | null; method: string;
    gross: number; lop_days: number; loss_of_pay: number; income_tax: number; eobi: number; provident_fund: number; other_deduction: number;
    total_deductions: number; employer_eobi: number; employer_pf: number; net_pay: number; paid_amount: number; status: string;
};
type Payment = {
    id: number; number: string | null; date: string; account: string | null; amount: number; status: string;
    journal: string | null; journal_id: number | null; reversal_journal: string | null; reversal_journal_id: number | null;
};
type Run = {
    id: number; number: string | null; period: string; accrual_date: string; status: string; memo: string | null;
    journal: string | null; journal_id: number | null; reversal_journal: string | null; reversal_journal_id: number | null;
    gross_total: number; deduction_total: number; lop_total: number; employer_contrib_total: number; net_total: number;
    payslips: Payslip[]; payments: Payment[];
};
type Option = { id: number; code: string; name: string };

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });

const statusStyle: Record<string, string> = {
    draft: 'bg-white/20 text-white',
    posted: 'bg-white/20 text-white',
    partially_paid: 'bg-white/20 text-white',
    paid: 'bg-white/20 text-white',
    reversed: 'bg-white/20 text-white',
};

export default function PayrollShow({ run, payAccounts, today }: { run: Run; payAccounts: Option[]; today: string }) {
    const { can } = usePermissions();
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [payOpen, setPayOpen] = useState(false);

    const outstanding = run.payslips.reduce((s, p) => s + (p.net_pay - p.paid_amount), 0);
    const isDraft = run.status === 'draft';
    const isReversed = run.status === 'reversed';
    const canPay = !isDraft && !isReversed && outstanding > 0.005;
    const canReverseAccrual = run.status === 'posted';

    const tiles = [
        { label: 'Gross Earnings', value: run.gross_total, from: '#6366f1', to: '#4338ca' },
        { label: 'Deductions', value: run.deduction_total, from: '#f43f5e', to: '#be185d' },
        { label: 'Employer Contrib.', value: run.employer_contrib_total, from: '#f59e0b', to: '#c2410c' },
        { label: 'Net Pay', value: run.net_total, from: '#10b981', to: '#0f766e' },
    ];

    const postAccrual = () => router.post(`/hr/payroll/${run.id}/post`, {}, { preserveScroll: true });
    const reverseAccrual = () => {
        if (confirm('Reverse this payroll accrual? A mirror journal will be posted and the accrual undone.')) {
            router.post(`/hr/payroll/${run.id}/reverse`, {}, { preserveScroll: true });
        }
    };
    const reversePayment = (paymentId: number) => {
        if (confirm('Reverse this payment? Net pay will become outstanding again.')) {
            router.post(`/hr/payroll-payments/${paymentId}/reverse`, {}, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title={`Payroll ${run.number ?? ''}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <Link href="/hr/payroll" className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1 text-sm"><ArrowLeft className="size-4" /> All runs</Link>

                <div className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={gradient('#312e81', '#4f46e5')}>
                    <div className="relative flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <div className="flex items-center gap-2 text-xs font-medium uppercase tracking-widest text-indigo-200">
                                <span className="font-mono">{run.number ?? 'Draft'}</span>
                                <Badge variant="secondary" className={`capitalize ${statusStyle[run.status] ?? ''}`}>{run.status.replace('_', ' ')}</Badge>
                            </div>
                            <h1 className="mt-1 text-3xl font-bold tracking-tight">{run.period}</h1>
                            <p className="mt-1 text-sm text-indigo-100/80">Accrual date {run.accrual_date}{run.memo ? ` · ${run.memo}` : ''}</p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {run.journal_id && (
                                <Button asChild variant="secondary" className="bg-white/15 text-white hover:bg-white/25">
                                    <Link href={`/accounting/journals/${run.journal_id}`}><BookOpenText className="size-4" /> {run.journal}</Link>
                                </Button>
                            )}
                            {run.reversal_journal_id && (
                                <Button asChild variant="secondary" className="bg-white/15 text-white hover:bg-white/25">
                                    <Link href={`/accounting/journals/${run.reversal_journal_id}`}><Undo2 className="size-4" /> {run.reversal_journal}</Link>
                                </Button>
                            )}
                            {isDraft && can('hr.payroll.run') && (
                                <Button onClick={postAccrual} className="bg-white text-indigo-700 hover:bg-white/90"><CheckCircle2 className="size-4" /> Post accrual</Button>
                            )}
                            {canPay && can('hr.payroll.pay') && (
                                <Dialog open={payOpen} onOpenChange={setPayOpen}>
                                    <DialogTrigger asChild>
                                        <Button className="bg-white text-emerald-700 hover:bg-white/90"><HandCoins className="size-4" /> Record payment</Button>
                                    </DialogTrigger>
                                    <PayDialog runId={run.id} payAccounts={payAccounts} today={today} outstanding={outstanding} onDone={() => setPayOpen(false)} />
                                </Dialog>
                            )}
                            {canReverseAccrual && can('hr.payroll.run') && (
                                <Button onClick={reverseAccrual} className="bg-rose-600 text-white hover:bg-rose-700"><Undo2 className="size-4" /> Reverse accrual</Button>
                            )}
                        </div>
                    </div>
                </div>

                {errors.posting && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">{errors.posting}</div>}
                {errors.payment && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">{errors.payment}</div>}

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {tiles.map((t) => (
                        <div key={t.label} className="relative overflow-hidden rounded-2xl p-4 text-white shadow-md" style={gradient(t.from, t.to)}>
                            <Banknote className="absolute -bottom-3 -right-3 size-20 opacity-20" />
                            <div className="relative">
                                <div className="text-xs font-medium uppercase tracking-wide text-white/80">{t.label}</div>
                                <div className="mt-2 font-mono text-2xl font-bold tabular-nums">{money(t.value)}</div>
                            </div>
                        </div>
                    ))}
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[960px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-3 [&>th]:py-2.5 [&>th]:font-medium">
                                <th className="text-left">Employee</th>
                                <th className="text-right">Gross</th>
                                <th className="text-right">LOP</th>
                                <th className="text-right">Income Tax</th>
                                <th className="text-right">EOBI</th>
                                <th className="text-right">PF</th>
                                <th className="text-right">Other</th>
                                <th className="text-right">Net Pay</th>
                                <th className="text-right">Paid</th>
                                <th className="text-left">Status</th>
                                <th className="w-16"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {run.payslips.map((p) => (
                                <tr key={p.id} className="hover:bg-muted/40 [&>td]:px-3 [&>td]:py-2">
                                    <td className="text-left">
                                        <div className="font-medium">{p.employee}</div>
                                        <div className="text-muted-foreground text-xs capitalize">{p.department ?? '—'} · {p.method}</div>
                                    </td>
                                    <td className="text-right font-mono tabular-nums">{money(p.gross)}</td>
                                    <td className="text-right font-mono tabular-nums" title={p.lop_days ? `${p.lop_days} day(s)` : undefined}>{moneyOrDash(p.loss_of_pay)}</td>
                                    <td className="text-right font-mono tabular-nums">{moneyOrDash(p.income_tax)}</td>
                                    <td className="text-right font-mono tabular-nums">{moneyOrDash(p.eobi)}</td>
                                    <td className="text-right font-mono tabular-nums">{moneyOrDash(p.provident_fund)}</td>
                                    <td className="text-right font-mono tabular-nums">{moneyOrDash(p.other_deduction)}</td>
                                    <td className="text-right font-mono font-semibold tabular-nums">{money(p.net_pay)}</td>
                                    <td className="text-right font-mono tabular-nums">{moneyOrDash(p.paid_amount)}</td>
                                    <td className="text-left">
                                        <Badge variant="secondary" className={`capitalize ${p.status === 'paid' ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400' : p.status === 'reversed' ? 'bg-rose-500/15 text-rose-600 dark:text-rose-400' : 'bg-sky-500/15 text-sky-600 dark:text-sky-400'}`}>{p.status}</Badge>
                                    </td>
                                    <td className="text-right">
                                        <a href={`/hr/payroll/${run.id}/payslip/${p.id}`} target="_blank" rel="noopener" title="Open payslip" className="text-muted-foreground hover:text-foreground inline-flex items-center justify-center">
                                            <FileText className="size-4" />
                                        </a>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="bg-muted/30 font-medium">
                            <tr className="[&>td]:px-3 [&>td]:py-2.5">
                                <td className="text-left">Total ({run.payslips.length})</td>
                                <td className="text-right font-mono tabular-nums">{money(run.gross_total)}</td>
                                <td className="text-right font-mono tabular-nums">{moneyOrDash(run.lop_total)}</td>
                                <td colSpan={4} className="text-right font-mono tabular-nums text-muted-foreground">Deductions {money(run.deduction_total)}</td>
                                <td className="text-right font-mono tabular-nums">{money(run.net_total)}</td>
                                <td colSpan={3} />
                            </tr>
                        </tfoot>
                    </table>
                </div>

                {run.payments.length > 0 && (
                    <div>
                        <h2 className="text-muted-foreground mb-3 text-sm font-semibold uppercase tracking-wide">Payments</h2>
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full min-w-[640px] text-sm">
                                <thead className="bg-muted/50 text-muted-foreground">
                                    <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                        <th className="text-left">Number</th>
                                        <th className="text-left">Date</th>
                                        <th className="text-left">Paid from</th>
                                        <th className="text-right">Amount</th>
                                        <th className="text-left">Status</th>
                                        <th className="text-left">Journal</th>
                                        <th className="w-24"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {run.payments.map((p) => (
                                        <tr key={p.id} className="hover:bg-muted/40 [&>td]:px-4 [&>td]:py-2">
                                            <td className="font-mono text-xs">{p.number ?? '—'}</td>
                                            <td className="tabular-nums">{p.date}</td>
                                            <td className="font-mono text-xs">{p.account ?? '—'}</td>
                                            <td className="text-right font-mono tabular-nums">{money(p.amount)}</td>
                                            <td>
                                                <Badge variant="secondary" className={`capitalize ${p.status === 'reversed' ? 'bg-rose-500/15 text-rose-600 dark:text-rose-400' : 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'}`}>{p.status}</Badge>
                                            </td>
                                            <td className="text-xs">
                                                {p.journal_id && <Link href={`/accounting/journals/${p.journal_id}`} className="text-indigo-600 hover:underline dark:text-indigo-400">{p.journal}</Link>}
                                                {p.reversal_journal_id && <> · <Link href={`/accounting/journals/${p.reversal_journal_id}`} className="text-rose-600 hover:underline dark:text-rose-400">{p.reversal_journal}</Link></>}
                                            </td>
                                            <td className="text-right">
                                                {p.status === 'posted' && can('hr.payroll.pay') && (
                                                    <Button variant="ghost" size="sm" onClick={() => reversePayment(p.id)} className="text-rose-600 hover:text-rose-700 hover:bg-rose-500/10"><Undo2 className="size-4" /> Reverse</Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                <p className="text-muted-foreground text-xs">
                    Accrual: Dr Salaries &amp; Wages + employer EOBI/PF; Cr per-employee Wages Payable, tax, EOBI, PF &amp; other deductions. Payment relieves each employee’s payable against cash/bank. Reversing posts a mirror journal — the original stays for the audit trail.
                </p>
            </div>
        </>
    );
}

function PayDialog({ runId, payAccounts, today, outstanding, onDone }: { runId: number; payAccounts: Option[]; today: string; outstanding: number; onDone: () => void }) {
    const form = useForm({ paid_from_account_id: '', payment_date: today, memo: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/hr/payroll/${runId}/pay`, { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); } });
    };

    return (
        <DialogContent className="sm:max-w-md">
            <DialogHeader><DialogTitle>Record payroll payment</DialogTitle></DialogHeader>
            <form onSubmit={submit} className="grid gap-4">
                <div className="bg-muted/40 flex items-center justify-between rounded-lg px-3 py-2 text-sm">
                    <span className="text-muted-foreground">Outstanding net pay</span>
                    <span className="font-mono font-semibold tabular-nums">{money(outstanding)}</span>
                </div>
                <div className="grid gap-1.5">
                    <Label>Pay from</Label>
                    <Select value={form.data.paid_from_account_id} onValueChange={(v) => form.setData('paid_from_account_id', v)}>
                        <SelectTrigger><SelectValue placeholder="Cash / bank account" /></SelectTrigger>
                        <SelectContent>{payAccounts.map((a) => <SelectItem key={a.id} value={String(a.id)}>{a.code} — {a.name}</SelectItem>)}</SelectContent>
                    </Select>
                    {form.errors.paid_from_account_id && <p className="text-destructive text-xs">{form.errors.paid_from_account_id}</p>}
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="pd">Payment date</Label>
                    <Input id="pd" type="date" value={form.data.payment_date} onChange={(e) => form.setData('payment_date', e.target.value)} />
                    {form.errors.payment_date && <p className="text-destructive text-xs">{form.errors.payment_date}</p>}
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="pm">Memo</Label>
                    <Input id="pm" value={form.data.memo} onChange={(e) => form.setData('memo', e.target.value)} placeholder="Optional" />
                </div>
                <DialogFooter>
                    <Button type="submit" disabled={form.processing}>Disburse {money(outstanding)}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}
