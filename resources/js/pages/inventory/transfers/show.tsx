import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { money } from '@/lib/format';

type Line = { item: string; quantity: number; description: string | null };

type Transfer = {
    id: number;
    number: string | null;
    date: string;
    from: string;
    to: string;
    memo: string | null;
    status: string;
    lines: Line[];
};

export default function ShowTransfer({ transfer }: { transfer: Transfer }) {
    return (
        <>
            <Head title={`Transfer ${transfer.number ?? ''}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex items-center gap-3">
                    <Button asChild variant="ghost" size="icon"><Link href="/inventory/transfers"><ArrowLeft className="size-4" /></Link></Button>
                    <div>
                        <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">
                            {transfer.number}
                            <Badge variant="secondary" className="capitalize">{transfer.status}</Badge>
                        </h1>
                        <p className="text-muted-foreground text-sm">{transfer.date}</p>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-3 rounded-xl border p-4 text-sm">
                    <span className="font-medium">{transfer.from}</span>
                    <ArrowRight className="text-muted-foreground size-4" />
                    <span className="font-medium">{transfer.to}</span>
                    {transfer.memo && <span className="text-muted-foreground ml-auto">{transfer.memo}</span>}
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th>Item</th>
                                <th className="w-40 text-right">Quantity</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {transfer.lines.map((l, i) => (
                                <tr key={i}>
                                    <td className="px-4 py-2.5">{l.item}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(l.quantity)}</td>
                                    <td className="text-muted-foreground px-4 py-2.5">{l.description ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
