import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus, RotateCcw, Save, SlidersHorizontal, Trash2 } from 'lucide-react';
import { type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Settings = { eobi_wage_base: number; eobi_employee_rate: number; eobi_employer_rate: number; pf_rate: number };
type Slab = { lower_bound: string; base_tax: string; rate: string };
type SlabIn = { lower_bound: number; base_tax: number; rate: number };

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });
const pct = (fraction: number) => String(Math.round(fraction * 1_000_000) / 10_000); // 0.0833 -> "8.33"

export default function PayrollSettings({
    settings, slabs, defaults,
}: {
    settings: Settings; slabs: SlabIn[]; defaults: Settings;
}) {
    const form = useForm({
        eobi_wage_base: String(settings.eobi_wage_base),
        eobi_employee_rate: pct(settings.eobi_employee_rate),
        eobi_employer_rate: pct(settings.eobi_employer_rate),
        pf_rate: pct(settings.pf_rate),
        slabs: slabs.map((s) => ({ lower_bound: String(s.lower_bound), base_tax: String(s.base_tax), rate: pct(s.rate) })) as Slab[],
    });

    const slabsError = (form.errors as Record<string, string>).slabs;

    const setSlab = (i: number, field: keyof Slab, value: string) =>
        form.setData('slabs', form.data.slabs.map((s, idx) => (idx === i ? { ...s, [field]: value } : s)));
    const addSlab = () => form.setData('slabs', [...form.data.slabs, { lower_bound: '', base_tax: '', rate: '' }]);
    const removeSlab = (i: number) => form.setData('slabs', form.data.slabs.filter((_, idx) => idx !== i));

    const resetDefaults = () => {
        form.setData('eobi_wage_base', String(defaults.eobi_wage_base));
        form.setData('eobi_employee_rate', pct(defaults.eobi_employee_rate));
        form.setData('eobi_employer_rate', pct(defaults.eobi_employer_rate));
        form.setData('pf_rate', pct(defaults.pf_rate));
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            eobi_wage_base: parseFloat(data.eobi_wage_base) || 0,
            eobi_employee_rate: (parseFloat(data.eobi_employee_rate) || 0) / 100,
            eobi_employer_rate: (parseFloat(data.eobi_employer_rate) || 0) / 100,
            pf_rate: (parseFloat(data.pf_rate) || 0) / 100,
            slabs: data.slabs.map((s) => ({
                lower_bound: parseFloat(s.lower_bound) || 0,
                base_tax: parseFloat(s.base_tax) || 0,
                rate: (parseFloat(s.rate) || 0) / 100,
            })),
        }));
        form.put('/hr/payroll/settings', { preserveScroll: true });
    };

    const rateField = (key: 'eobi_employee_rate' | 'eobi_employer_rate' | 'pf_rate', label: string) => (
        <div className="grid gap-1.5">
            <Label htmlFor={key}>{label}</Label>
            <div className="relative">
                <Input id={key} inputMode="decimal" value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} className="pr-8 text-right font-mono" />
                <span className="text-muted-foreground pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm">%</span>
            </div>
            {form.errors[key] && <p className="text-destructive text-xs">{form.errors[key]}</p>}
        </div>
    );

    return (
        <>
            <Head title="Payroll Settings" />
            <form onSubmit={submit} className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-4 md:p-6">
                <Link href="/hr/payroll" className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1 text-sm"><ArrowLeft className="size-4" /> Back to payroll</Link>

                <div className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={gradient('#312e81', '#4f46e5')}>
                    <SlidersHorizontal className="pointer-events-none absolute -bottom-6 -right-4 size-40 opacity-15" />
                    <div className="relative flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <div className="text-xs font-medium uppercase tracking-widest text-indigo-200">Payroll</div>
                            <h1 className="mt-1 text-3xl font-bold tracking-tight">Configuration</h1>
                            <p className="mt-1 text-sm text-indigo-100/80">Statutory rates &amp; income-tax slabs. Changes apply to runs created afterwards.</p>
                        </div>
                        <Button type="submit" disabled={form.processing} className="bg-white text-indigo-700 hover:bg-white/90"><Save className="size-4" /> Save</Button>
                    </div>
                </div>

                {form.recentlySuccessful && (
                    <div className="rounded-md border border-emerald-500/40 bg-emerald-500/10 px-4 py-2.5 text-sm text-emerald-700 dark:text-emerald-400">Configuration saved.</div>
                )}

                {/* EOBI & PF */}
                <div className="bg-card rounded-2xl border p-5 shadow-sm">
                    <div className="mb-4 flex items-center justify-between">
                        <h2 className="text-sm font-semibold uppercase tracking-wide">EOBI &amp; Provident Fund</h2>
                        <Button type="button" variant="ghost" size="sm" onClick={resetDefaults} className="text-muted-foreground"><RotateCcw className="size-3.5" /> Pakistan defaults</Button>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="grid gap-1.5">
                            <Label htmlFor="eobi_wage_base">EOBI wage base</Label>
                            <Input id="eobi_wage_base" inputMode="decimal" value={form.data.eobi_wage_base} onChange={(e) => form.setData('eobi_wage_base', e.target.value)} className="text-right font-mono" />
                            {form.errors.eobi_wage_base && <p className="text-destructive text-xs">{form.errors.eobi_wage_base}</p>}
                        </div>
                        {rateField('eobi_employee_rate', 'EOBI employee')}
                        {rateField('eobi_employer_rate', 'EOBI employer')}
                        {rateField('pf_rate', 'Provident fund (of basic)')}
                    </div>
                    <p className="text-muted-foreground mt-3 text-xs">EOBI is charged on the fixed wage base; PF is a share of each employee’s basic salary, matched by the employer.</p>
                </div>

                {/* Tax slabs */}
                <div className="bg-card rounded-2xl border p-5 shadow-sm">
                    <div className="mb-4 flex items-center justify-between">
                        <h2 className="text-sm font-semibold uppercase tracking-wide">Annual Income-Tax Slabs</h2>
                        <Button type="button" variant="outline" size="sm" onClick={addSlab}><Plus className="size-4" /> Add slab</Button>
                    </div>
                    {slabsError && <div className="border-destructive/40 bg-destructive/10 text-destructive mb-3 rounded-md border px-3 py-2 text-xs">{slabsError}</div>}
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full min-w-[520px] text-sm">
                            <thead className="bg-muted/50 text-muted-foreground">
                                <tr className="[&>th]:px-3 [&>th]:py-2 [&>th]:text-left [&>th]:font-medium">
                                    <th>Annual income over</th>
                                    <th>Tax on that amount</th>
                                    <th className="w-28">Rate above</th>
                                    <th className="w-10"></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {form.data.slabs.map((s, i) => (
                                    <tr key={i} className="[&>td]:px-3 [&>td]:py-1.5">
                                        <td><Input inputMode="decimal" value={s.lower_bound} onChange={(e) => setSlab(i, 'lower_bound', e.target.value)} className="text-right font-mono" placeholder="0" /></td>
                                        <td><Input inputMode="decimal" value={s.base_tax} onChange={(e) => setSlab(i, 'base_tax', e.target.value)} className="text-right font-mono" placeholder="0" /></td>
                                        <td>
                                            <div className="relative">
                                                <Input inputMode="decimal" value={s.rate} onChange={(e) => setSlab(i, 'rate', e.target.value)} className="pr-7 text-right font-mono" placeholder="0" />
                                                <span className="text-muted-foreground pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-xs">%</span>
                                            </div>
                                        </td>
                                        <td className="text-center">
                                            {form.data.slabs.length > 1 && <Button type="button" variant="ghost" size="icon" onClick={() => removeSlab(i)}><Trash2 className="text-muted-foreground size-4" /></Button>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <p className="text-muted-foreground mt-3 text-xs">The first slab must start at 0. Tax = “tax on that amount” + (annual income − “over”) × rate, using the highest slab the income reaches. Monthly withholding is annual tax ÷ 12.</p>
                </div>
            </form>
        </>
    );
}
