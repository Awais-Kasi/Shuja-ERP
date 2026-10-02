import { Head, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, ShieldCheck, Trash2 } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Perm = { name: string; label: string };
type Group = { group: string; permissions: Perm[] };
type Role = { id: number; name: string; slug: string; description: string | null; is_system: boolean; users: number; permissions: string[] };

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });

function PermissionPicker({ catalogue, selected, toggle, toggleGroup }: { catalogue: Group[]; selected: Set<string>; toggle: (n: string) => void; toggleGroup: (g: Group, on: boolean) => void }) {
    return (
        <div className="max-h-[50vh] space-y-4 overflow-y-auto rounded-lg border p-3">
            {catalogue.map((g) => {
                const all = g.permissions.every((p) => selected.has(p.name));
                return (
                    <div key={g.group}>
                        <label className="mb-1.5 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            <Checkbox checked={all} onCheckedChange={(v) => toggleGroup(g, v === true)} /> {g.group}
                        </label>
                        <div className="grid gap-1.5 pl-6 sm:grid-cols-2">
                            {g.permissions.map((p) => (
                                <label key={p.name} className="flex items-center gap-2 text-sm">
                                    <Checkbox checked={selected.has(p.name)} onCheckedChange={() => toggle(p.name)} /> {p.label}
                                </label>
                            ))}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

export default function RolesIndex({ roles, catalogue }: { roles: Role[]; catalogue: Group[] }) {
    const [addOpen, setAddOpen] = useState(false);
    const [edit, setEdit] = useState<Role | null>(null);

    return (
        <>
            <Head title="Roles & Permissions" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={gradient('#0f766e', '#4338ca')}>
                    <ShieldCheck className="pointer-events-none absolute -bottom-6 -right-4 size-40 opacity-15" />
                    <div className="relative flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <div className="text-xs font-medium uppercase tracking-widest text-teal-100">Administration</div>
                            <h1 className="mt-1 text-3xl font-bold tracking-tight">Roles &amp; Permissions</h1>
                            <p className="mt-1 text-sm text-teal-50/80">Define what each role can do across the system.</p>
                        </div>
                        <Dialog open={addOpen} onOpenChange={setAddOpen}>
                            <DialogTrigger asChild><Button className="bg-white text-teal-700 hover:bg-white/90"><Plus className="size-4" /> New role</Button></DialogTrigger>
                            <RoleDialog catalogue={catalogue} onDone={() => setAddOpen(false)} />
                        </Dialog>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[640px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground"><tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium"><th className="text-left">Role</th><th className="text-left">Description</th><th className="text-center">Users</th><th className="text-center">Permissions</th><th className="w-24"></th></tr></thead>
                        <tbody className="divide-y">
                            {roles.map((r) => (
                                <tr key={r.id} className="[&>td]:px-4 [&>td]:py-2.5">
                                    <td className="font-medium">{r.name}{r.is_system && <Badge variant="secondary" className="ml-2">System</Badge>}</td>
                                    <td className="text-muted-foreground">{r.description ?? '—'}</td>
                                    <td className="text-center tabular-nums">{r.users}</td>
                                    <td className="text-center tabular-nums">{r.permissions.length}</td>
                                    <td>
                                        <div className="flex justify-end gap-1">
                                            <Button variant="ghost" size="icon" title="Edit" onClick={() => setEdit(r)}><Pencil className="size-4" /></Button>
                                            {!r.is_system && <Button variant="ghost" size="icon" title="Delete" onClick={() => { if (confirm(`Delete role ${r.name}?`)) router.delete(`/admin/roles/${r.id}`, { preserveScroll: true }); }}><Trash2 className="size-4 text-rose-500" /></Button>}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {edit && <RoleDialog role={edit} catalogue={catalogue} onDone={() => setEdit(null)} />}
        </>
    );
}

function RoleDialog({ role, catalogue, onDone }: { role?: Role; catalogue: Group[]; onDone: () => void }) {
    const [selected, setSelected] = useState<Set<string>>(new Set(role?.permissions ?? []));
    const form = useForm({ name: role?.name ?? '', description: role?.description ?? '' });

    const toggle = (n: string) => setSelected((s) => { const next = new Set(s); next.has(n) ? next.delete(n) : next.add(n); return next; });
    const toggleGroup = (g: Group, on: boolean) => setSelected((s) => { const next = new Set(s); g.permissions.forEach((p) => on ? next.add(p.name) : next.delete(p.name)); return next; });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, permissions: Array.from(selected) }));
        if (role) form.put(`/admin/roles/${role.id}`, { preserveScroll: true, onSuccess: onDone });
        else form.post('/admin/roles', { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); } });
    };

    const inner = (
        <DialogContent className="sm:max-w-2xl">
            <DialogHeader><DialogTitle>{role ? `Edit ${role.name}` : 'New role'}</DialogTitle></DialogHeader>
            <form onSubmit={submit} className="grid gap-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-1.5"><Label>Name</Label><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />{form.errors.name && <p className="text-destructive text-xs">{form.errors.name}</p>}</div>
                    <div className="grid gap-1.5"><Label>Description</Label><Input value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} placeholder="Optional" /></div>
                </div>
                <div>
                    <div className="mb-1.5 flex items-center justify-between"><Label>Permissions</Label><span className="text-muted-foreground text-xs">{selected.size} selected</span></div>
                    <PermissionPicker catalogue={catalogue} selected={selected} toggle={toggle} toggleGroup={toggleGroup} />
                </div>
                <DialogFooter><Button type="submit" disabled={form.processing}>{role ? 'Save role' : 'Create role'}</Button></DialogFooter>
            </form>
        </DialogContent>
    );

    return role ? <Dialog open onOpenChange={(o) => !o && onDone()}>{inner}</Dialog> : inner;
}
