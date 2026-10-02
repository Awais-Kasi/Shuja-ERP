import {
    ArrowLeftRight,
    Banknote,
    BookOpen,
    BookOpenText,
    BookUser,
    Boxes,
    Building2,
    CalendarCheck,
    CalendarClock,
    ClipboardList,
    Coins,
    Contact,
    Factory,
    FileText,
    HandCoins,
    LayoutGrid,
    Landmark,
    ListTree,
    Package,
    PackageCheck,
    Receipt,
    ReceiptText,
    Repeat,
    Scale,
    ScrollText,
    ShieldCheck,
    ShoppingCart,
    Target,
    TrendingDown,
    TrendingUp,
    Undo2,
    Truck,
    Users,
    Wallet,
    Warehouse,
} from 'lucide-react';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { CompanySwitcher } from '@/components/company-switcher';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { usePermissions } from '@/hooks/use-permissions';
import type { NavGroup } from '@/types';

const navGroups: NavGroup[] = [
    {
        label: 'Overview',
        items: [{ title: 'Dashboard', href: '/dashboard', icon: LayoutGrid, permission: 'dashboard.view' }],
    },
    {
        label: 'Inventory',
        items: [
            { title: 'Items', href: '/inventory/items', icon: Package, permission: 'inventory.item.view' },
            { title: 'Warehouses', href: '/inventory/warehouses', icon: Warehouse, permission: 'inventory.item.view' },
            { title: 'Stock Balance', href: '/inventory/stock-balance', icon: Boxes, permission: 'inventory.stock.view' },
            { title: 'Stock Ledger', href: '/inventory/stock-ledger', icon: ScrollText, permission: 'inventory.stock.view' },
            { title: 'Stock Valuation', href: '/reports/inventory-valuation', icon: Scale, permission: 'inventory.valuation.view' },
            { title: 'Stock Aging', href: '/reports/stock-aging', icon: CalendarClock, permission: 'inventory.valuation.view' },
            { title: 'Adjustments', href: '/inventory/adjustments', icon: ClipboardList, permission: 'inventory.stock.view' },
            { title: 'Transfers', href: '/inventory/transfers', icon: ArrowLeftRight, permission: 'inventory.stock.view' },
        ],
    },
    {
        label: 'Purchase',
        items: [
            { title: 'Suppliers', href: '/purchase/suppliers', icon: Building2, permission: 'purchase.supplier.manage' },
            { title: 'Purchase Orders', href: '/purchase/orders', icon: ShoppingCart, permission: 'purchase.order.manage' },
            { title: 'Goods Receipts', href: '/purchase/receipts', icon: PackageCheck, permission: 'purchase.grn.create' },
            { title: 'Bills', href: '/purchase/bills', icon: ReceiptText, permission: 'purchase.bill.post' },
            { title: 'Supplier Payments', href: '/purchase/payments', icon: Banknote, permission: 'purchase.payment.manage' },
            { title: 'Purchase Returns', href: '/purchase/returns', icon: Undo2, permission: 'purchase.return.create' },
            { title: 'Supplier Ledger', href: '/purchase/supplier-ledger', icon: BookUser, permission: 'purchase.order.manage' },
        ],
    },
    {
        label: 'Sales',
        items: [
            { title: 'Customers', href: '/sales/customers', icon: Contact, permission: 'sales.customer.manage' },
            { title: 'Sales Orders', href: '/sales/orders', icon: FileText, permission: 'sales.order.manage' },
            { title: 'Invoices', href: '/sales/invoices', icon: Receipt, permission: 'sales.invoice.post' },
            { title: 'Customer Receipts', href: '/sales/receipts', icon: HandCoins, permission: 'sales.receipt.manage' },
            { title: 'Sales Returns', href: '/sales/returns', icon: Undo2, permission: 'sales.return.create' },
            { title: 'Customer Ledger', href: '/sales/customer-ledger', icon: BookOpen, permission: 'sales.order.manage' },
        ],
    },
    {
        label: 'Manufacturing',
        items: [
            { title: 'Bills of Material', href: '/manufacturing/boms', icon: ListTree, permission: 'manufacturing.bom.manage' },
            { title: 'Work Orders', href: '/manufacturing/work-orders', icon: Factory, permission: 'manufacturing.workorder.manage' },
        ],
    },
    {
        label: 'Consignment',
        items: [
            { title: 'Dispatches', href: '/consignment/dispatches', icon: Truck, permission: 'consignment.manage' },
            { title: 'Settlements', href: '/consignment/settlements', icon: HandCoins, permission: 'consignment.settle' },
            { title: 'Consignment Stock', href: '/consignment/stock', icon: Boxes, permission: 'consignment.manage' },
        ],
    },
    {
        label: 'Finance',
        items: [
            { title: 'Chart of Accounts', href: '/accounting/accounts', icon: Landmark, permission: 'accounting.account.view' },
            { title: 'Journal Entries', href: '/accounting/journals', icon: BookOpenText, permission: 'accounting.journal.view' },
            { title: 'Recurring Journals', href: '/accounting/recurring', icon: Repeat, permission: 'accounting.recurring.view' },
            { title: 'FX Revaluation', href: '/accounting/fx-revaluation', icon: Coins, permission: 'accounting.fx.view' },
            { title: 'Trial Balance', href: '/accounting/trial-balance', icon: Scale, permission: 'accounting.report.view' },
            { title: 'Income Statement', href: '/accounting/income-statement', icon: TrendingUp, permission: 'accounting.report.view' },
            { title: 'Balance Sheet', href: '/accounting/balance-sheet', icon: BookOpen, permission: 'accounting.report.view' },
            { title: 'Cash Flow', href: '/accounting/cash-flow', icon: ArrowLeftRight, permission: 'accounting.report.view' },
            { title: 'Aged Receivables', href: '/reports/aged-receivables', icon: BookUser, permission: 'accounting.report.view' },
            { title: 'Aged Payables', href: '/reports/aged-payables', icon: BookUser, permission: 'accounting.report.view' },
            { title: 'Sales Tax Return', href: '/reports/sales-tax', icon: ReceiptText, permission: 'accounting.report.view' },
            { title: 'Period Close', href: '/accounting/periods', icon: CalendarClock, permission: 'accounting.period.lock' },
            { title: 'Bank Reconciliation', href: '/banking/reconciliations', icon: Landmark, permission: 'banking.view' },
            { title: 'Budgets', href: '/budgeting/budgets', icon: Target, permission: 'budget.view' },
            { title: 'Fixed Assets', href: '/assets', icon: Building2, permission: 'fixedasset.view' },
            { title: 'Depreciation', href: '/assets/depreciation', icon: TrendingDown, permission: 'fixedasset.depreciate' },
        ],
    },
    {
        label: 'People',
        items: [
            { title: 'Employees', href: '/hr/employees', icon: Users, permission: 'hr.employee.view' },
            { title: 'Attendance', href: '/hr/attendance', icon: CalendarCheck, permission: 'hr.attendance.manage' },
            { title: 'Payroll', href: '/hr/payroll', icon: Wallet, permission: 'hr.payroll.run' },
        ],
    },
    {
        label: 'Administration',
        items: [
            { title: 'Users', href: '/admin/users', icon: Users, permission: 'user.manage' },
            { title: 'Roles & Permissions', href: '/admin/roles', icon: ShieldCheck, permission: 'role.manage' },
            { title: 'Companies', href: '/admin/companies', icon: Building2, permission: 'company.manage' },
            { title: 'Audit Log', href: '/admin/audit', icon: ScrollText, permission: 'audit.view' },
        ],
    },
];

export function AppSidebar() {
    const { can } = usePermissions();

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <CompanySwitcher />
            </SidebarHeader>

            <SidebarContent>
                {navGroups.map((group) => {
                    const items = group.items.filter(
                        (item) => !item.permission || can(item.permission),
                    );

                    return <NavMain key={group.label} label={group.label} items={items} />;
                })}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
