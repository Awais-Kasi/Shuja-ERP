import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Factory } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Line = { component: string; quantity: number };
type Bom = { id: number; code: string; name: string; output: string; output_qty: number; lines: Line[] };

export default function ShowBom({ bom }: { bom: Bom }) {
    const { can } = usePermissions();
    return (
        <>
            <Head title={`BOM ${bom.code}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Button asChild variant="ghost" size="icon"><Link href="/manufacturing/boms"><ArrowLeft className="size-4" /></Link></Button>
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight">{bom.code} — {bom.name}</h1>
                            <p className="text-muted-foreground text-sm">Produces {money(bom.output_qty)} × {bom.output}</p>
                        </div>
                    </div>
                    {can('manufacturing.workorder.manage') && (
                        <Button asChild><Link href="/manufacturing/work-orders/create"><Factory className="size-4" /> New work order</Link></Button>
                    )}
                </div>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th>Component</th>
                                <th className="w-48 text-right">Quantity / batch</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {bom.lines.map((l, i) => (
                                <tr key={i}>
                                    <td className="px-4 py-2.5">{l.component}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(l.quantity)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
