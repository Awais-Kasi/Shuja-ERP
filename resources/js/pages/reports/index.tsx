import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    BookOpen,
    Boxes,
    Landmark,
    Receipt,
    ShoppingCart,
    Truck,
    Users,
} from 'lucide-react';
import { usePermissions } from '@/hooks/use-permissions';

type ReportLink = { title: string; desc: string; href: string; permission?: string };
type Group = { label: string; icon: typeof Receipt; reports: ReportLink[] };

const GROUPS: Group[] = [
    {
        label: 'Sales',
        icon: Receipt,
        reports: [
            { title: 'Sales Register', desc: 'Every posted invoice for a period, with tax and totals.', href: '/sales/sales-register', permission: 'sales.order.manage' },
            { title: 'Customer Ledger', desc: 'Outstanding balance owed by each customer.', href: '/sales/customer-ledger', permission: 'sales.order.manage' },
            { title: 'Aged Receivables', desc: 'What customers owe you, bucketed by how overdue.', href: '/reports/aged-receivables', permission: 'accounting.report.view' },
            { title: 'Sales Tax Return', desc: 'Output vs input tax for a period, tied to the ledger.', href: '/reports/sales-tax', permission: 'accounting.report.view' },
        ],
    },
    {
        label: 'Purchase',
        icon: ShoppingCart,
        reports: [
            { title: 'Purchase Register', desc: 'Every posted supplier bill for a period, with tax and totals.', href: '/purchase/purchase-register', permission: 'purchase.order.manage' },
            { title: 'Supplier Ledger', desc: 'Outstanding balance owed to each supplier.', href: '/purchase/supplier-ledger', permission: 'purchase.order.manage' },
            { title: 'Aged Payables', desc: 'What you owe suppliers, bucketed by how overdue.', href: '/reports/aged-payables', permission: 'accounting.report.view' },
        ],
    },
    {
        label: 'Inventory',
        icon: Boxes,
        reports: [
            { title: 'Stock Balance', desc: 'Quantity and value on hand per item and warehouse.', href: '/inventory/stock-balance', permission: 'inventory.stock.view' },
            { title: 'Stock Ledger', desc: 'Every stock movement, in and out, with reasons.', href: '/inventory/stock-ledger', permission: 'inventory.stock.view' },
            { title: 'Stock Valuation', desc: 'Total value of inventory held, by item.', href: '/reports/inventory-valuation', permission: 'inventory.valuation.view' },
            { title: 'Stock Aging', desc: 'How long stock has been sitting, in age buckets.', href: '/reports/stock-aging', permission: 'inventory.valuation.view' },
        ],
    },
    {
        label: 'Accounting',
        icon: Landmark,
        reports: [
            { title: 'Trial Balance', desc: 'Every account balance — proof the books balance.', href: '/accounting/trial-balance', permission: 'accounting.report.view' },
            { title: 'General Ledger', desc: 'All postings to an account over a period.', href: '/accounting/general-ledger', permission: 'accounting.report.view' },
            { title: 'Income Statement', desc: 'Profit & loss — revenue, cost of sales, expenses.', href: '/accounting/income-statement', permission: 'accounting.report.view' },
            { title: 'Balance Sheet', desc: 'Assets, liabilities and equity at a point in time.', href: '/accounting/balance-sheet', permission: 'accounting.report.view' },
            { title: 'Cash Flow', desc: 'Where cash came from and went — operating, investing, financing.', href: '/accounting/cash-flow', permission: 'accounting.report.view' },
        ],
    },
    {
        label: 'Consignment',
        icon: Truck,
        reports: [
            { title: 'Consignment Stock', desc: 'Goods held by each agent, reconciled to the ledger.', href: '/consignment/stock', permission: 'consignment.manage' },
        ],
    },
    {
        label: 'Payroll',
        icon: Users,
        reports: [
            { title: 'Statutory Report', desc: 'Tax, EOBI and provident-fund totals for a payroll period.', href: '/hr/payroll/statutory-report', permission: 'hr.payroll.run' },
        ],
    },
];

export default function ReportsHub() {
    const { can } = usePermissions();
    const groups = GROUPS
        .map((g) => ({ ...g, reports: g.reports.filter((r) => !r.permission || can(r.permission)) }))
        .filter((g) => g.reports.length > 0);

    return (
        <>
            <Head title="Reports" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Reports</h1>
                    <p className="text-muted-foreground text-sm">Every report in the system, organised by module. Pick one to open it.</p>
                </div>

                {groups.length === 0 && (
                    <div className="text-muted-foreground rounded-xl border p-10 text-center">You don't have access to any reports. Ask your administrator.</div>
                )}

                <div className="flex flex-col gap-8">
                    {groups.map((g) => {
                        const Icon = g.icon;
                        return (
                            <section key={g.label} className="flex flex-col gap-3">
                                <div className="flex items-center gap-2">
                                    <span className="bg-primary/10 text-primary flex size-7 items-center justify-center rounded-lg">
                                        <Icon className="size-4" />
                                    </span>
                                    <h2 className="text-base font-semibold tracking-tight">{g.label}</h2>
                                    <span className="bg-muted text-muted-foreground ml-1 rounded-full px-2 py-0.5 text-xs">{g.reports.length}</span>
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    {g.reports.map((r) => (
                                        <Link
                                            key={r.href}
                                            href={r.href}
                                            className="group hover:border-primary/60 hover:bg-accent/40 flex flex-col gap-1 rounded-xl border p-4 transition"
                                        >
                                            <div className="flex items-center justify-between">
                                                <span className="font-medium">{r.title}</span>
                                                <ArrowRight className="text-muted-foreground group-hover:text-primary size-4 transition-transform group-hover:translate-x-0.5" />
                                            </div>
                                            <span className="text-muted-foreground text-xs leading-relaxed">{r.desc}</span>
                                        </Link>
                                    ))}
                                </div>
                            </section>
                        );
                    })}
                </div>
            </div>
        </>
    );
}
