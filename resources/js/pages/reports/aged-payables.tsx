import { AgingReport } from './aged-receivables';

type Row = { party: string; current: number; d1_30: number; d31_60: number; d61_90: number; d90_plus: number; total: number; currencies?: string };
type Report = { as_of: string; base_currency?: string; has_foreign?: boolean; rows: Row[]; totals: Omit<Row, 'party'> };

export default function AgedPayables({ report }: { report: Report }) {
    return <AgingReport title="Aged Payables" subtitle="Outstanding supplier balances by age" partyLabel="Supplier" report={report} />;
}
