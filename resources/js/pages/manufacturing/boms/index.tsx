import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Row = { id: number; code: string; name: string; output: string; output_qty: number; components: number; is_active: boolean };

export default function BomsIndex({ boms }: { boms: Row[] }) {
    const { can } = usePermissions();
    return (
        <>
            <Head title="Bills of Material" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Bills of Material</h1>
                        <p className="text-muted-foreground text-sm">{boms.length} recipes</p>
                    </div>
                    {can('manufacturing.bom.manage') && <Button asChild><Link href="/manufacturing/boms/create"><Plus className="size-4" /> New BOM</Link></Button>}
                </div>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th className="w-32">Code</th>
                                <th>Name</th>
                                <th>Output</th>
                                <th className="w-24 text-right">Batch</th>
                                <th className="w-28 text-right">Components</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {boms.length === 0 && <tr><td colSpan={5} className="text-muted-foreground px-4 py-10 text-center">No bills of material yet.</td></tr>}
                            {boms.map((b) => (
                                <tr key={b.id} onClick={() => router.visit(`/manufacturing/boms/${b.id}`)} className="hover:bg-muted/40 cursor-pointer">
                                    <td className="px-4 py-2.5 font-mono text-xs">{b.code}</td>
                                    <td className="px-4 py-2.5">{b.name}</td>
                                    <td className="text-muted-foreground px-4 py-2.5">{b.output}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(b.output_qty)}</td>
                                    <td className="px-4 py-2.5 text-right tabular-nums">{b.components}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
