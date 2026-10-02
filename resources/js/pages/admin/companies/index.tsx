import { Head, useForm } from '@inertiajs/react';
import { Building2, Pencil, Plus } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

type Company = {
    id: number; name: string; legal_name: string | null; code: string; base_currency: string; country: string | null;
    timezone: string | null; fiscal_start_month: number; tax_registration_no: string | null; address: string | null; is_active: boolean;
};

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

export default function CompaniesIndex({ companies, currencies }: { companies: Company[]; currencies: string[] }) {
    const [addOpen, setAddOpen] = useState(false);
    const [edit, setEdit] = useState<Company | null>(null);

    return (
        <>
            <Head title="Companies" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={gradient('#1e3a8a', '#0f172a')}>
                    <Building2 className="pointer-events-none absolute -bottom-6 -right-4 size-40 opacity-15" />
                    <div className="relative flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <div className="text-xs font-medium uppercase tracking-widest text-blue-200">Administration</div>
                            <h1 className="mt-1 text-3xl font-bold tracking-tight">Companies</h1>
                            <p className="mt-1 text-sm text-blue-100/80">Each company is a self-contained ledger. New companies are fully provisioned on creation.</p>
                        </div>
                        <Dialog open={addOpen} onOpenChange={setAddOpen}>
                            <DialogTrigger asChild><Button className="bg-white text-blue-800 hover:bg-white/90"><Plus className="size-4" /> New company</Button></DialogTrigger>
                            <AddDialog currencies={currencies} onDone={() => setAddOpen(false)} />
                        </Dialog>
                    </div>
                </div>

                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    {companies.map((c) => (
                        <div key={c.id} className="rounded-2xl border bg-card p-4 shadow-sm">
                            <div className="flex items-start justify-between">
                                <div>
                                    <div className="font-semibold">{c.name}</div>
                                    <div className="text-muted-foreground text-xs">{c.legal_name ?? c.code}</div>
                                </div>
                                <Button variant="ghost" size="icon" onClick={() => setEdit(c)}><Pencil className="size-4" /></Button>
                            </div>
                            <div className="mt-3 flex flex-wrap gap-2 text-xs">
                                <Badge variant="secondary" className="font-mono">{c.code}</Badge>
                                <Badge variant="secondary">{c.base_currency}</Badge>
                                <Badge variant="secondary">FY starts {MONTHS[(c.fiscal_start_month || 1) - 1]}</Badge>
                                {c.is_active ? <Badge variant="secondary" className="bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">Active</Badge> : <Badge variant="secondary" className="bg-rose-500/15 text-rose-600 dark:text-rose-400">Inactive</Badge>}
                            </div>
                            {c.tax_registration_no && <div className="text-muted-foreground mt-2 text-xs">NTN/STRN: {c.tax_registration_no}</div>}
                        </div>
                    ))}
                </div>
            </div>

            {edit && <EditDialog company={edit} onClose={() => setEdit(null)} />}
        </>
    );
}

function AddDialog({ currencies, onDone }: { currencies: string[]; onDone: () => void }) {
    const form = useForm({ name: '', legal_name: '', code: '', base_currency: currencies[0] ?? 'PKR', country: 'PK', timezone: 'Asia/Karachi', fiscal_start_month: '7' });
    const submit = (e: FormEvent) => { e.preventDefault(); form.post('/admin/companies', { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); } }); };
    const err = (k: keyof typeof form.data) => form.errors[k] && <p className="text-destructive text-xs">{form.errors[k]}</p>;
    return (
        <DialogContent className="sm:max-w-lg">
            <DialogHeader><DialogTitle>New company</DialogTitle></DialogHeader>
            <form onSubmit={submit} className="grid gap-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-1.5"><Label>Name</Label><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />{err('name')}</div>
                    <div className="grid gap-1.5"><Label>Code</Label><Input value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} placeholder="ACME" />{err('code')}</div>
                    <div className="grid gap-1.5 sm:col-span-2"><Label>Legal name</Label><Input value={form.data.legal_name} onChange={(e) => form.setData('legal_name', e.target.value)} placeholder="Optional" /></div>
                    <div className="grid gap-1.5"><Label>Base currency</Label>
                        <Select value={form.data.base_currency} onValueChange={(v) => form.setData('base_currency', v)}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent>{currencies.map((c) => <SelectItem key={c} value={c}>{c}</SelectItem>)}</SelectContent></Select>
                        {err('base_currency')}
                    </div>
                    <div className="grid gap-1.5"><Label>Fiscal year starts</Label>
                        <Select value={form.data.fiscal_start_month} onValueChange={(v) => form.setData('fiscal_start_month', v)}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent>{MONTHS.map((m, i) => <SelectItem key={m} value={String(i + 1)}>{m}</SelectItem>)}</SelectContent></Select>
                    </div>
                    <div className="grid gap-1.5"><Label>Country</Label><Input value={form.data.country} onChange={(e) => form.setData('country', e.target.value.toUpperCase())} maxLength={2} /></div>
                    <div className="grid gap-1.5"><Label>Timezone</Label><Input value={form.data.timezone} onChange={(e) => form.setData('timezone', e.target.value)} /></div>
                </div>
                <p className="text-muted-foreground text-xs">Creates the chart of accounts, fiscal calendar, number sequences and an Owner role, and adds you as owner.</p>
                <DialogFooter><Button type="submit" disabled={form.processing}>Create &amp; provision</Button></DialogFooter>
            </form>
        </DialogContent>
    );
}

function EditDialog({ company, onClose }: { company: Company; onClose: () => void }) {
    const form = useForm({
        name: company.name, legal_name: company.legal_name ?? '', country: company.country ?? '', timezone: company.timezone ?? '',
        tax_registration_no: company.tax_registration_no ?? '', address: company.address ?? '', is_active: company.is_active,
    });
    const submit = (e: FormEvent) => { e.preventDefault(); form.put(`/admin/companies/${company.id}`, { preserveScroll: true, onSuccess: onClose }); };
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader><DialogTitle>Edit {company.name}</DialogTitle></DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-1.5"><Label>Name</Label><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></div>
                        <div className="grid gap-1.5"><Label>Legal name</Label><Input value={form.data.legal_name} onChange={(e) => form.setData('legal_name', e.target.value)} /></div>
                        <div className="grid gap-1.5"><Label>Country</Label><Input value={form.data.country} onChange={(e) => form.setData('country', e.target.value.toUpperCase())} maxLength={2} /></div>
                        <div className="grid gap-1.5"><Label>Timezone</Label><Input value={form.data.timezone} onChange={(e) => form.setData('timezone', e.target.value)} /></div>
                        <div className="grid gap-1.5 sm:col-span-2"><Label>Tax registration (NTN/STRN)</Label><Input value={form.data.tax_registration_no} onChange={(e) => form.setData('tax_registration_no', e.target.value)} /></div>
                        <div className="grid gap-1.5 sm:col-span-2"><Label>Address</Label><Input value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} /></div>
                    </div>
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} /> Active</label>
                    <DialogFooter><Button type="submit" disabled={form.processing}>Save</Button></DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
