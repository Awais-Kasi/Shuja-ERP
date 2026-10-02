import { Head } from '@inertiajs/react';
import { Construction } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';

type PlaceholderProps = {
    module: string;
    phase: number;
};

export default function ModulePlaceholder({ module, phase }: PlaceholderProps) {
    return (
        <>
            <Head title={module} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight">{module}</h1>
                    <Badge variant="secondary" className="font-mono">
                        Phase {phase}
                    </Badge>
                </div>

                <Card className="flex-1">
                    <CardContent className="flex h-full flex-col items-center justify-center gap-4 py-16 text-center">
                        <div className="bg-muted flex size-14 items-center justify-center rounded-full">
                            <Construction className="text-muted-foreground size-7" />
                        </div>
                        <div className="space-y-1">
                            <p className="text-lg font-medium">This module is on the roadmap</p>
                            <p className="text-muted-foreground mx-auto max-w-md text-sm">
                                <span className="text-foreground font-medium">{module}</span> is scheduled
                                for <span className="text-foreground font-medium">Phase {phase}</span> of the
                                build. The foundation it will sit on — multi-company tenancy, RBAC, the fiscal
                                calendar and the accounting spine — is being put in place first.
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
