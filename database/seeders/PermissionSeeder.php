<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * The full catalogue of abilities, grouped by module.
     * Format: 'ability.slug' => 'Human label'.
     *
     * @var array<string, array<string, string>>
     */
    public const CATALOGUE = [
        'Platform' => [
            'dashboard.view' => 'View dashboard',
            'company.manage' => 'Manage companies',
            'user.manage' => 'Manage users',
            'role.manage' => 'Manage roles & permissions',
            'audit.view' => 'View audit log',
            'settings.manage' => 'Manage settings',
        ],
        'Accounting' => [
            'accounting.account.view' => 'View chart of accounts',
            'accounting.account.manage' => 'Manage chart of accounts',
            'accounting.journal.view' => 'View journals',
            'accounting.journal.create' => 'Create journals',
            'accounting.journal.post' => 'Post journals',
            'accounting.recurring.view' => 'View recurring journals',
            'accounting.recurring.manage' => 'Manage & run recurring journals',
            'accounting.fx.view' => 'View FX revaluation',
            'accounting.fx.manage' => 'Run FX revaluation & manage rates',
            'accounting.period.lock' => 'Lock accounting periods',
            'accounting.period.close' => 'Close & reopen fiscal years',
            'accounting.report.view' => 'View financial reports',
        ],
        'Inventory' => [
            'inventory.item.view' => 'View items',
            'inventory.item.manage' => 'Manage items',
            'inventory.stock.view' => 'View stock',
            'inventory.adjustment.create' => 'Create stock adjustments',
            'inventory.valuation.view' => 'View stock valuation',
        ],
        'Purchase' => [
            'purchase.supplier.manage' => 'Manage suppliers',
            'purchase.order.manage' => 'Manage purchase orders',
            'purchase.grn.create' => 'Create goods receipts',
            'purchase.bill.post' => 'Post purchase bills',
            'purchase.payment.manage' => 'Record supplier payments',
            'purchase.return.create' => 'Create purchase returns',
        ],
        'Sales' => [
            'sales.customer.manage' => 'Manage customers',
            'sales.order.manage' => 'Manage sales orders',
            'sales.invoice.post' => 'Post sales invoices',
            'sales.receipt.manage' => 'Record customer receipts',
            'sales.return.create' => 'Create sales returns',
        ],
        'Manufacturing' => [
            'manufacturing.bom.manage' => 'Manage bills of material',
            'manufacturing.workorder.manage' => 'Manage work orders',
        ],
        'Consignment' => [
            'consignment.manage' => 'Manage consignments',
            'consignment.expense.manage' => 'Manage consignment expenses',
            'consignment.settle' => 'Settle consignments',
        ],
        'Fixed Assets' => [
            'fixedasset.view' => 'View fixed assets',
            'fixedasset.manage' => 'Manage fixed assets',
            'fixedasset.depreciate' => 'Run depreciation',
        ],
        'Banking' => [
            'banking.view' => 'View bank reconciliations',
            'banking.reconcile' => 'Reconcile bank accounts',
        ],
        'Budgeting' => [
            'budget.view' => 'View budgets & variance',
            'budget.manage' => 'Manage budgets',
        ],
        'HR' => [
            'hr.employee.view' => 'View employees',
            'hr.employee.manage' => 'Manage employees',
            'hr.attendance.manage' => 'Manage attendance',
            'hr.payroll.run' => 'Run payroll',
            'hr.payroll.pay' => 'Disburse payroll',
            'hr.payroll.configure' => 'Configure payroll rates & tax slabs',
        ],
    ];

    public function run(): void
    {
        foreach (self::CATALOGUE as $group => $abilities) {
            foreach ($abilities as $name => $label) {
                Permission::updateOrCreate(
                    ['name' => $name],
                    ['label' => $label, 'group' => $group],
                );
            }
        }
    }
}
