import { Head, router, useForm } from '@inertiajs/react';
import { KeyRound, Pencil, Trash2, UserPlus, Users } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

type Role = { id: number; name: string };
type UserRow = { id: number; name: string; email: string; role_id: number | null; role: string; is_super_admin: boolean; verified: boolean };

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });

export default function UsersIndex({ users, roles }: { users: UserRow[]; roles: Role[] }) {
    const [addOpen, setAddOpen] = useState(false);
    const [edit, setEdit] = useState<UserRow | null>(null);
    const [pw, setPw] = useState<UserRow | null>(null);

    return (
        <>
            <Head title="Users" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={gradient('#4338ca', '#6d28d9')}>
                    <Users className="pointer-events-none absolute -bottom-6 -right-4 size-40 opacity-15" />
                    <div className="relative flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <div className="text-xs font-medium uppercase tracking-widest text-indigo-200">Administration</div>
                            <h1 className="mt-1 text-3xl font-bold tracking-tight">Users</h1>
                            <p className="mt-1 text-sm text-indigo-100/80">Manage who can sign in to this company and what role they hold.</p>
                        </div>
                        <Dialog open={addOpen} onOpenChange={setAddOpen}>
                            <DialogTrigger asChild><Button className="bg-white text-indigo-700 hover:bg-white/90"><UserPlus className="size-4" /> Add user</Button></DialogTrigger>
                            <AddDialog roles={roles} onDone={() => setAddOpen(false)} />
                        </Dialog>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[720px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground"><tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium"><th className="text-left">Name</th><th className="text-left">Email</th><th className="text-left">Role</th><th className="text-left">Status</th><th className="w-32"></th></tr></thead>
                        <tbody className="divide-y">
                            {users.map((u) => (
                                <tr key={u.id} className="[&>td]:px-4 [&>td]:py-2.5">
                                    <td className="font-medium">{u.name}{u.is_super_admin && <Badge variant="secondary" className="ml-2 bg-amber-500/15 text-amber-600 dark:text-amber-400">Super</Badge>}</td>
                                    <td className="text-muted-foreground">{u.email}</td>
                                    <td>{u.role}</td>
                                    <td>{u.verified ? <Badge variant="secondary" className="bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">Active</Badge> : <Badge variant="secondary">Pending</Badge>}</td>
                                    <td>
                                        <div className="flex justify-end gap-1">
                                            <Button variant="ghost" size="icon" title="Edit" onClick={() => setEdit(u)}><Pencil className="size-4" /></Button>
                                            <Button variant="ghost" size="icon" title="Reset password" onClick={() => setPw(u)}><KeyRound className="size-4" /></Button>
                                            <Button variant="ghost" size="icon" title="Remove" onClick={() => { if (confirm(`Remove ${u.name} from this company?`)) router.delete(`/admin/users/${u.id}`, { preserveScroll: true }); }}><Trash2 className="size-4 text-rose-500" /></Button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {edit && <EditDialog user={edit} roles={roles} onClose={() => setEdit(null)} />}
            {pw && <PasswordDialog user={pw} onClose={() => setPw(null)} />}
        </>
    );
}

function AddDialog({ roles, onDone }: { roles: Role[]; onDone: () => void }) {
    const form = useForm({ name: '', email: '', password: '', role_id: roles[0] ? String(roles[0].id) : '' });
    const submit = (e: FormEvent) => { e.preventDefault(); form.post('/admin/users', { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); } }); };
    const err = (k: keyof typeof form.data) => form.errors[k] && <p className="text-destructive text-xs">{form.errors[k]}</p>;
    return (
        <DialogContent className="sm:max-w-md">
            <DialogHeader><DialogTitle>Add user</DialogTitle></DialogHeader>
            <form onSubmit={submit} className="grid gap-4">
                <div className="grid gap-1.5"><Label>Name</Label><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />{err('name')}</div>
                <div className="grid gap-1.5"><Label>Email</Label><Input type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />{err('email')}</div>
                <div className="grid gap-1.5"><Label>Temporary password</Label><Input type="text" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />{err('password')}</div>
                <div className="grid gap-1.5"><Label>Role</Label>
                    <Select value={form.data.role_id} onValueChange={(v) => form.setData('role_id', v)}><SelectTrigger><SelectValue placeholder="Role" /></SelectTrigger><SelectContent>{roles.map((r) => <SelectItem key={r.id} value={String(r.id)}>{r.name}</SelectItem>)}</SelectContent></Select>
                    {err('role_id')}
                </div>
                <DialogFooter><Button type="submit" disabled={form.processing}>Create user</Button></DialogFooter>
            </form>
        </DialogContent>
    );
}

function EditDialog({ user, roles, onClose }: { user: UserRow; roles: Role[]; onClose: () => void }) {
    const form = useForm({ name: user.name, email: user.email, role_id: user.role_id ? String(user.role_id) : '' });
    const submit = (e: FormEvent) => { e.preventDefault(); form.put(`/admin/users/${user.id}`, { preserveScroll: true, onSuccess: onClose }); };
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader><DialogTitle>Edit {user.name}</DialogTitle></DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    <div className="grid gap-1.5"><Label>Name</Label><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />{form.errors.name && <p className="text-destructive text-xs">{form.errors.name}</p>}</div>
                    <div className="grid gap-1.5"><Label>Email</Label><Input type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />{form.errors.email && <p className="text-destructive text-xs">{form.errors.email}</p>}</div>
                    <div className="grid gap-1.5"><Label>Role</Label>
                        <Select value={form.data.role_id} onValueChange={(v) => form.setData('role_id', v)}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent>{roles.map((r) => <SelectItem key={r.id} value={String(r.id)}>{r.name}</SelectItem>)}</SelectContent></Select>
                    </div>
                    <DialogFooter><Button type="submit" disabled={form.processing}>Save</Button></DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function PasswordDialog({ user, onClose }: { user: UserRow; onClose: () => void }) {
    const form = useForm({ password: '' });
    const submit = (e: FormEvent) => { e.preventDefault(); form.post(`/admin/users/${user.id}/reset-password`, { preserveScroll: true, onSuccess: onClose }); };
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader><DialogTitle>Reset password — {user.name}</DialogTitle></DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    <div className="grid gap-1.5"><Label>New password</Label><Input type="text" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />{form.errors.password && <p className="text-destructive text-xs">{form.errors.password}</p>}</div>
                    <DialogFooter><Button type="submit" disabled={form.processing}>Set password</Button></DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
