<?php

use App\Http\Controllers\Accounting\AccountController;
use App\Http\Controllers\Accounting\FxRevaluationController;
use App\Http\Controllers\Accounting\JournalController;
use App\Http\Controllers\Accounting\PeriodController;
use App\Http\Controllers\Accounting\RecurringJournalController;
use App\Http\Controllers\Accounting\ReportController;
use App\Http\Controllers\Banking\BankReconciliationController;
use App\Http\Controllers\Budgeting\BudgetController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FixedAssets\FixedAssetController;
use App\Http\Controllers\Hr\AttendanceController;
use App\Http\Controllers\Hr\EmployeeController;
use App\Http\Controllers\Hr\PayrollRunController;
use App\Http\Controllers\Hr\PayrollSettingController;
use App\Http\Controllers\Consignment\ConsignmentDispatchController;
use App\Http\Controllers\Consignment\ConsignmentExpenseController;
use App\Http\Controllers\Consignment\ConsignmentReportController;
use App\Http\Controllers\Consignment\ConsignmentSettlementController;
use App\Http\Controllers\Inventory\ItemController;
use App\Http\Controllers\Payments\PaymentController;
use App\Http\Controllers\Manufacturing\BomController;
use App\Http\Controllers\Manufacturing\WorkOrderController;
use App\Http\Controllers\Inventory\StockAdjustmentController;
use App\Http\Controllers\Inventory\StockReportController;
use App\Http\Controllers\Inventory\StockTransferController;
use App\Http\Controllers\Inventory\UomController;
use App\Http\Controllers\Inventory\WarehouseController;
use App\Http\Controllers\Purchasing\GoodsReceiptController;
use App\Http\Controllers\Purchasing\PurchaseBillController;
use App\Http\Controllers\Purchasing\PurchaseOrderController;
use App\Http\Controllers\Purchasing\PurchaseReportController;
use App\Http\Controllers\Purchasing\SupplierController;
use App\Http\Controllers\Sales\CustomerController;
use App\Http\Controllers\Sales\SalesInvoiceController;
use App\Http\Controllers\Sales\SalesOrderController;
use App\Http\Controllers\Sales\SalesReportController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// The app opens on the login page (or the dashboard when already signed in).
Route::get('/', fn () => redirect()->route(Auth::check() ? 'dashboard' : 'login'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Active-company switching
    Route::post('companies/switch', [CompanyController::class, 'switch'])->name('companies.switch');

    // Accounting & Finance (Phase 1)
    Route::prefix('accounting')->name('accounting.')->group(function () {
        Route::get('accounts', [AccountController::class, 'index'])->middleware('can:accounting.account.view')->name('accounts.index');
        Route::post('accounts', [AccountController::class, 'store'])->middleware('can:accounting.account.manage')->name('accounts.store');

        Route::get('journals', [JournalController::class, 'index'])->middleware('can:accounting.journal.view')->name('journals.index');
        Route::get('journals/create', [JournalController::class, 'create'])->middleware('can:accounting.journal.create')->name('journals.create');
        Route::post('journals', [JournalController::class, 'store'])->middleware('can:accounting.journal.post')->name('journals.store');
        Route::get('journals/{journal}', [JournalController::class, 'show'])->middleware('can:accounting.journal.view')->name('journals.show');
        Route::post('journals/{journal}/reverse', [JournalController::class, 'reverse'])->middleware('can:accounting.journal.post')->name('journals.reverse');

        Route::get('trial-balance', [ReportController::class, 'trialBalance'])->middleware('can:accounting.report.view')->name('reports.trial-balance');
        Route::get('general-ledger', [ReportController::class, 'generalLedger'])->middleware('can:accounting.report.view')->name('reports.general-ledger');
        Route::get('income-statement', [ReportController::class, 'incomeStatement'])->middleware('can:accounting.report.view')->name('reports.income-statement');
        Route::get('balance-sheet', [ReportController::class, 'balanceSheet'])->middleware('can:accounting.report.view')->name('reports.balance-sheet');
        Route::get('cash-flow', [ReportController::class, 'cashFlow'])->middleware('can:accounting.report.view')->name('reports.cash-flow');

        // Recurring journals
        Route::get('recurring', [RecurringJournalController::class, 'index'])->middleware('can:accounting.recurring.view')->name('recurring.index');
        Route::get('recurring/create', [RecurringJournalController::class, 'create'])->middleware('can:accounting.recurring.manage')->name('recurring.create');
        Route::post('recurring', [RecurringJournalController::class, 'store'])->middleware('can:accounting.recurring.manage')->name('recurring.store');
        Route::post('recurring/run', [RecurringJournalController::class, 'runDue'])->middleware('can:accounting.recurring.manage')->name('recurring.run');
        Route::get('recurring/{recurring}', [RecurringJournalController::class, 'show'])->middleware('can:accounting.recurring.view')->name('recurring.show');
        Route::post('recurring/{recurring}/run', [RecurringJournalController::class, 'runOne'])->middleware('can:accounting.recurring.manage')->name('recurring.run-one');
        Route::post('recurring/{recurring}/pause', [RecurringJournalController::class, 'pause'])->middleware('can:accounting.recurring.manage')->name('recurring.pause');
        Route::post('recurring/{recurring}/resume', [RecurringJournalController::class, 'resume'])->middleware('can:accounting.recurring.manage')->name('recurring.resume');
        Route::delete('recurring/{recurring}', [RecurringJournalController::class, 'destroy'])->middleware('can:accounting.recurring.manage')->name('recurring.destroy');

        // Multi-currency revaluation
        Route::get('fx-revaluation', [FxRevaluationController::class, 'index'])->middleware('can:accounting.fx.view')->name('fx.index');
        Route::post('fx-revaluation/rates', [FxRevaluationController::class, 'storeRate'])->middleware('can:accounting.fx.manage')->name('fx.rates.store');
        Route::post('fx-revaluation', [FxRevaluationController::class, 'run'])->middleware('can:accounting.fx.manage')->name('fx.run');
        Route::get('fx-revaluation/{revaluation}', [FxRevaluationController::class, 'show'])->middleware('can:accounting.fx.view')->name('fx.show');

        // Period & year-end close
        Route::get('periods', [PeriodController::class, 'index'])->middleware('can:accounting.period.lock')->name('periods.index');
        Route::post('periods/{period}/lock', [PeriodController::class, 'lock'])->middleware('can:accounting.period.lock')->name('periods.lock');
        Route::post('periods/{period}/unlock', [PeriodController::class, 'unlock'])->middleware('can:accounting.period.lock')->name('periods.unlock');
        Route::post('fiscal-years/{fiscalYear}/close', [PeriodController::class, 'closeYear'])->middleware('can:accounting.period.close')->name('periods.close');
        Route::post('fiscal-years/{fiscalYear}/reopen', [PeriodController::class, 'reopenYear'])->middleware('can:accounting.period.close')->name('periods.reopen');
    });

    // Inventory & Stock (Phase 2)
    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('items', [ItemController::class, 'index'])->middleware('can:inventory.item.view')->name('items.index');
        Route::post('items', [ItemController::class, 'store'])->middleware('can:inventory.item.manage')->name('items.store');

        Route::get('warehouses', [WarehouseController::class, 'index'])->middleware('can:inventory.item.view')->name('warehouses.index');
        Route::post('warehouses', [WarehouseController::class, 'store'])->middleware('can:inventory.item.manage')->name('warehouses.store');

        Route::get('uoms', [UomController::class, 'index'])->middleware('can:inventory.item.view')->name('uoms.index');
        Route::post('uoms', [UomController::class, 'store'])->middleware('can:inventory.item.manage')->name('uoms.store');

        Route::get('stock-balance', [StockReportController::class, 'balances'])->middleware('can:inventory.stock.view')->name('stock-balance');
        Route::get('stock-ledger', [StockReportController::class, 'ledger'])->middleware('can:inventory.stock.view')->name('stock-ledger');

        Route::get('adjustments', [StockAdjustmentController::class, 'index'])->middleware('can:inventory.stock.view')->name('adjustments.index');
        Route::get('adjustments/create', [StockAdjustmentController::class, 'create'])->middleware('can:inventory.adjustment.create')->name('adjustments.create');
        Route::post('adjustments', [StockAdjustmentController::class, 'store'])->middleware('can:inventory.adjustment.create')->name('adjustments.store');
        Route::get('adjustments/{adjustment}', [StockAdjustmentController::class, 'show'])->middleware('can:inventory.stock.view')->name('adjustments.show');

        Route::get('transfers', [StockTransferController::class, 'index'])->middleware('can:inventory.stock.view')->name('transfers.index');
        Route::get('transfers/create', [StockTransferController::class, 'create'])->middleware('can:inventory.adjustment.create')->name('transfers.create');
        Route::post('transfers', [StockTransferController::class, 'store'])->middleware('can:inventory.adjustment.create')->name('transfers.store');
        Route::get('transfers/{transfer}', [StockTransferController::class, 'show'])->middleware('can:inventory.stock.view')->name('transfers.show');
    });

    // Purchase (Phase 3)
    Route::prefix('purchase')->name('purchase.')->group(function () {
        Route::get('suppliers', [SupplierController::class, 'index'])->middleware('can:purchase.supplier.manage')->name('suppliers.index');
        Route::post('suppliers', [SupplierController::class, 'store'])->middleware('can:purchase.supplier.manage')->name('suppliers.store');

        Route::get('orders', [PurchaseOrderController::class, 'index'])->middleware('can:purchase.order.manage')->name('orders.index');
        Route::get('orders/create', [PurchaseOrderController::class, 'create'])->middleware('can:purchase.order.manage')->name('orders.create');
        Route::post('orders', [PurchaseOrderController::class, 'store'])->middleware('can:purchase.order.manage')->name('orders.store');
        Route::get('orders/{order}', [PurchaseOrderController::class, 'show'])->middleware('can:purchase.order.manage')->name('orders.show');
        Route::get('orders/{order}/print', [PurchaseOrderController::class, 'print'])->middleware('can:purchase.order.manage')->name('orders.print');

        Route::get('receipts', [GoodsReceiptController::class, 'index'])->middleware('can:purchase.grn.create')->name('receipts.index');
        Route::get('receipts/create', [GoodsReceiptController::class, 'create'])->middleware('can:purchase.grn.create')->name('receipts.create');
        Route::post('receipts', [GoodsReceiptController::class, 'store'])->middleware('can:purchase.grn.create')->name('receipts.store');
        Route::get('receipts/{receipt}', [GoodsReceiptController::class, 'show'])->middleware('can:purchase.grn.create')->name('receipts.show');

        Route::get('bills', [PurchaseBillController::class, 'index'])->middleware('can:purchase.bill.post')->name('bills.index');
        Route::get('bills/create', [PurchaseBillController::class, 'create'])->middleware('can:purchase.bill.post')->name('bills.create');
        Route::post('bills', [PurchaseBillController::class, 'store'])->middleware('can:purchase.bill.post')->name('bills.store');
        Route::get('bills/{bill}', [PurchaseBillController::class, 'show'])->middleware('can:purchase.bill.post')->name('bills.show');

        Route::get('supplier-ledger', [PurchaseReportController::class, 'supplierLedger'])->middleware('can:purchase.order.manage')->name('supplier-ledger');

        // Purchase returns (debit notes)
        Route::get('returns', [\App\Http\Controllers\Purchasing\PurchaseReturnController::class, 'index'])->middleware('can:purchase.return.create')->name('returns.index');
        Route::get('returns/create', [\App\Http\Controllers\Purchasing\PurchaseReturnController::class, 'create'])->middleware('can:purchase.return.create')->name('returns.create');
        Route::post('returns', [\App\Http\Controllers\Purchasing\PurchaseReturnController::class, 'store'])->middleware('can:purchase.return.create')->name('returns.store');
        Route::get('returns/{return}', [\App\Http\Controllers\Purchasing\PurchaseReturnController::class, 'show'])->middleware('can:purchase.return.create')->name('returns.show');
        Route::post('returns/{return}/reverse', [\App\Http\Controllers\Purchasing\PurchaseReturnController::class, 'reverse'])->middleware('can:purchase.return.create')->name('returns.reverse');

        // Supplier payments (AP settlement)
        Route::get('payments', [PaymentController::class, 'index'])->defaults('direction', 'pay')->middleware('can:purchase.payment.manage')->name('payments.index');
        Route::get('payments/create', [PaymentController::class, 'create'])->defaults('direction', 'pay')->middleware('can:purchase.payment.manage')->name('payments.create');
        Route::post('payments', [PaymentController::class, 'store'])->defaults('direction', 'pay')->middleware('can:purchase.payment.manage')->name('payments.store');
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->defaults('direction', 'pay')->middleware('can:purchase.payment.manage')->name('payments.show');
        Route::post('payments/{payment}/reverse', [PaymentController::class, 'reverse'])->middleware('can:purchase.payment.manage')->name('payments.reverse');
    });

    // Sales (Phase 4)
    Route::prefix('sales')->name('sales.')->group(function () {
        Route::get('customers', [CustomerController::class, 'index'])->middleware('can:sales.customer.manage')->name('customers.index');
        Route::post('customers', [CustomerController::class, 'store'])->middleware('can:sales.customer.manage')->name('customers.store');

        Route::get('orders', [SalesOrderController::class, 'index'])->middleware('can:sales.order.manage')->name('orders.index');
        Route::get('orders/create', [SalesOrderController::class, 'create'])->middleware('can:sales.order.manage')->name('orders.create');
        Route::post('orders', [SalesOrderController::class, 'store'])->middleware('can:sales.order.manage')->name('orders.store');
        Route::get('orders/{order}', [SalesOrderController::class, 'show'])->middleware('can:sales.order.manage')->name('orders.show');

        Route::get('invoices', [SalesInvoiceController::class, 'index'])->middleware('can:sales.invoice.post')->name('invoices.index');
        Route::get('invoices/create', [SalesInvoiceController::class, 'create'])->middleware('can:sales.invoice.post')->name('invoices.create');
        Route::post('invoices', [SalesInvoiceController::class, 'store'])->middleware('can:sales.invoice.post')->name('invoices.store');
        Route::get('invoices/{invoice}', [SalesInvoiceController::class, 'show'])->middleware('can:sales.invoice.post')->name('invoices.show');
        Route::get('invoices/{invoice}/print', [SalesInvoiceController::class, 'print'])->middleware('can:sales.invoice.post')->name('invoices.print');

        Route::get('customer-ledger', [SalesReportController::class, 'customerLedger'])->middleware('can:sales.order.manage')->name('customer-ledger');

        // Sales returns (credit notes)
        Route::get('returns', [\App\Http\Controllers\Sales\SalesReturnController::class, 'index'])->middleware('can:sales.return.create')->name('returns.index');
        Route::get('returns/create', [\App\Http\Controllers\Sales\SalesReturnController::class, 'create'])->middleware('can:sales.return.create')->name('returns.create');
        Route::post('returns', [\App\Http\Controllers\Sales\SalesReturnController::class, 'store'])->middleware('can:sales.return.create')->name('returns.store');
        Route::get('returns/{return}', [\App\Http\Controllers\Sales\SalesReturnController::class, 'show'])->middleware('can:sales.return.create')->name('returns.show');
        Route::post('returns/{return}/reverse', [\App\Http\Controllers\Sales\SalesReturnController::class, 'reverse'])->middleware('can:sales.return.create')->name('returns.reverse');

        // Customer receipts (AR settlement)
        Route::get('receipts', [PaymentController::class, 'index'])->defaults('direction', 'receive')->middleware('can:sales.receipt.manage')->name('receipts.index');
        Route::get('receipts/create', [PaymentController::class, 'create'])->defaults('direction', 'receive')->middleware('can:sales.receipt.manage')->name('receipts.create');
        Route::post('receipts', [PaymentController::class, 'store'])->defaults('direction', 'receive')->middleware('can:sales.receipt.manage')->name('receipts.store');
        Route::get('receipts/{payment}', [PaymentController::class, 'show'])->defaults('direction', 'receive')->middleware('can:sales.receipt.manage')->name('receipts.show');
        Route::post('receipts/{payment}/reverse', [PaymentController::class, 'reverse'])->middleware('can:sales.receipt.manage')->name('receipts.reverse');
    });

    // Manufacturing (Phase 5)
    Route::prefix('manufacturing')->name('manufacturing.')->group(function () {
        Route::get('boms', [BomController::class, 'index'])->middleware('can:manufacturing.bom.manage')->name('boms.index');
        Route::get('boms/create', [BomController::class, 'create'])->middleware('can:manufacturing.bom.manage')->name('boms.create');
        Route::post('boms', [BomController::class, 'store'])->middleware('can:manufacturing.bom.manage')->name('boms.store');
        Route::get('boms/{bom}', [BomController::class, 'show'])->middleware('can:manufacturing.bom.manage')->name('boms.show');

        Route::get('work-orders', [WorkOrderController::class, 'index'])->middleware('can:manufacturing.workorder.manage')->name('work-orders.index');
        Route::get('work-orders/create', [WorkOrderController::class, 'create'])->middleware('can:manufacturing.workorder.manage')->name('work-orders.create');
        Route::post('work-orders', [WorkOrderController::class, 'store'])->middleware('can:manufacturing.workorder.manage')->name('work-orders.store');
        Route::get('work-orders/{workOrder}', [WorkOrderController::class, 'show'])->middleware('can:manufacturing.workorder.manage')->name('work-orders.show');
        Route::post('work-orders/{workOrder}/issue', [WorkOrderController::class, 'issue'])->middleware('can:manufacturing.workorder.manage')->name('work-orders.issue');
        Route::post('work-orders/{workOrder}/complete', [WorkOrderController::class, 'complete'])->middleware('can:manufacturing.workorder.manage')->name('work-orders.complete');
    });

    // Inter-warehouse Consignment (Phase 6)
    Route::prefix('consignment')->name('consignment.')->group(function () {
        Route::get('dispatches', [ConsignmentDispatchController::class, 'index'])->middleware('can:consignment.manage')->name('dispatches.index');
        Route::get('dispatches/create', [ConsignmentDispatchController::class, 'create'])->middleware('can:consignment.manage')->name('dispatches.create');
        Route::post('dispatches', [ConsignmentDispatchController::class, 'store'])->middleware('can:consignment.manage')->name('dispatches.store');
        Route::get('dispatches/{dispatch}', [ConsignmentDispatchController::class, 'show'])->middleware('can:consignment.manage')->name('dispatches.show');
        Route::post('dispatches/{dispatch}/receive', [ConsignmentDispatchController::class, 'receive'])->middleware('can:consignment.manage')->name('dispatches.receive');
        Route::post('dispatches/{dispatch}/reverse', [ConsignmentDispatchController::class, 'reverse'])->middleware('can:consignment.manage')->name('dispatches.reverse');

        Route::get('expenses/create', [ConsignmentExpenseController::class, 'create'])->middleware('can:consignment.expense.manage')->name('expenses.create');
        Route::post('expenses', [ConsignmentExpenseController::class, 'store'])->middleware('can:consignment.expense.manage')->name('expenses.store');
        Route::post('expenses/{expense}/reverse', [ConsignmentExpenseController::class, 'reverse'])->middleware('can:consignment.expense.manage')->name('expenses.reverse');

        Route::get('settlements', [ConsignmentSettlementController::class, 'index'])->middleware('can:consignment.settle')->name('settlements.index');
        Route::get('settlements/create', [ConsignmentSettlementController::class, 'create'])->middleware('can:consignment.settle')->name('settlements.create');
        Route::post('settlements', [ConsignmentSettlementController::class, 'store'])->middleware('can:consignment.settle')->name('settlements.store');
        Route::get('settlements/{settlement}', [ConsignmentSettlementController::class, 'show'])->middleware('can:consignment.settle')->name('settlements.show');
        Route::post('settlements/{settlement}/reverse', [ConsignmentSettlementController::class, 'reverse'])->middleware('can:consignment.settle')->name('settlements.reverse');

        Route::get('stock', [ConsignmentReportController::class, 'stock'])->middleware('can:consignment.manage')->name('stock');
    });

    // Fixed Assets & Depreciation
    Route::prefix('assets')->name('assets.')->group(function () {
        Route::get('/', [FixedAssetController::class, 'index'])->middleware('can:fixedasset.view')->name('index');
        Route::post('/', [FixedAssetController::class, 'store'])->middleware('can:fixedasset.manage')->name('store');
        Route::get('depreciation', [FixedAssetController::class, 'depreciationIndex'])->middleware('can:fixedasset.depreciate')->name('depreciation');
        Route::post('depreciation', [FixedAssetController::class, 'runDepreciation'])->middleware('can:fixedasset.depreciate')->name('depreciation.run');
        Route::get('{asset}', [FixedAssetController::class, 'show'])->middleware('can:fixedasset.view')->name('show');
        Route::post('{asset}/dispose', [FixedAssetController::class, 'dispose'])->middleware('can:fixedasset.manage')->name('dispose');
    });

    // Banking — Bank Reconciliation
    Route::prefix('banking')->name('banking.')->group(function () {
        Route::get('reconciliations', [BankReconciliationController::class, 'index'])->middleware('can:banking.view')->name('index');
        Route::post('reconciliations', [BankReconciliationController::class, 'store'])->middleware('can:banking.reconcile')->name('store');
        Route::get('reconciliations/{reconciliation}', [BankReconciliationController::class, 'show'])->middleware('can:banking.view')->name('show');
        Route::post('reconciliations/{reconciliation}/toggle', [BankReconciliationController::class, 'toggle'])->middleware('can:banking.reconcile')->name('toggle');
        Route::post('reconciliations/{reconciliation}/adjust', [BankReconciliationController::class, 'adjust'])->middleware('can:banking.reconcile')->name('adjust');
        Route::post('reconciliations/{reconciliation}/complete', [BankReconciliationController::class, 'complete'])->middleware('can:banking.reconcile')->name('complete');
        Route::post('reconciliations/{reconciliation}/reopen', [BankReconciliationController::class, 'reopen'])->middleware('can:banking.reconcile')->name('reopen');
    });

    // Budgeting vs Actuals
    Route::prefix('budgeting')->name('budgeting.')->group(function () {
        Route::get('budgets', [BudgetController::class, 'index'])->middleware('can:budget.view')->name('index');
        Route::post('budgets', [BudgetController::class, 'store'])->middleware('can:budget.manage')->name('store');
        Route::get('budgets/{budget}', [BudgetController::class, 'show'])->middleware('can:budget.view')->name('show');
        Route::post('budgets/{budget}/lines', [BudgetController::class, 'storeLine'])->middleware('can:budget.manage')->name('lines.store');
        Route::delete('budgets/{budget}/lines/{line}', [BudgetController::class, 'destroyLine'])->middleware('can:budget.manage')->name('lines.destroy');
        Route::delete('budgets/{budget}', [BudgetController::class, 'destroy'])->middleware('can:budget.manage')->name('destroy');
    });

    // Operational reports — aging & inventory
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('aged-receivables', [\App\Http\Controllers\Reports\OperationalReportController::class, 'agedReceivables'])->middleware('can:accounting.report.view')->name('aged-receivables');
        Route::get('aged-payables', [\App\Http\Controllers\Reports\OperationalReportController::class, 'agedPayables'])->middleware('can:accounting.report.view')->name('aged-payables');
        Route::get('inventory-valuation', [\App\Http\Controllers\Reports\OperationalReportController::class, 'inventoryValuation'])->middleware('can:inventory.valuation.view')->name('inventory-valuation');
        Route::get('stock-aging', [\App\Http\Controllers\Reports\OperationalReportController::class, 'stockAging'])->middleware('can:inventory.valuation.view')->name('stock-aging');
        Route::get('sales-tax', [\App\Http\Controllers\Reports\OperationalReportController::class, 'salesTax'])->middleware('can:accounting.report.view')->name('sales-tax');
    });

    // Administration
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('users', [\App\Http\Controllers\Admin\UserController::class, 'index'])->middleware('can:user.manage')->name('users.index');
        Route::post('users', [\App\Http\Controllers\Admin\UserController::class, 'store'])->middleware('can:user.manage')->name('users.store');
        Route::put('users/{user}', [\App\Http\Controllers\Admin\UserController::class, 'update'])->middleware('can:user.manage')->name('users.update');
        Route::post('users/{user}/reset-password', [\App\Http\Controllers\Admin\UserController::class, 'resetPassword'])->middleware('can:user.manage')->name('users.reset-password');
        Route::delete('users/{user}', [\App\Http\Controllers\Admin\UserController::class, 'destroy'])->middleware('can:user.manage')->name('users.destroy');

        Route::get('roles', [\App\Http\Controllers\Admin\RoleController::class, 'index'])->middleware('can:role.manage')->name('roles.index');
        Route::post('roles', [\App\Http\Controllers\Admin\RoleController::class, 'store'])->middleware('can:role.manage')->name('roles.store');
        Route::put('roles/{role}', [\App\Http\Controllers\Admin\RoleController::class, 'update'])->middleware('can:role.manage')->name('roles.update');
        Route::delete('roles/{role}', [\App\Http\Controllers\Admin\RoleController::class, 'destroy'])->middleware('can:role.manage')->name('roles.destroy');

        Route::get('companies', [\App\Http\Controllers\Admin\CompanyController::class, 'index'])->middleware('can:company.manage')->name('companies.index');
        Route::post('companies', [\App\Http\Controllers\Admin\CompanyController::class, 'store'])->middleware('can:company.manage')->name('companies.store');
        Route::put('companies/{company}', [\App\Http\Controllers\Admin\CompanyController::class, 'update'])->middleware('can:company.manage')->name('companies.update');

        Route::get('audit', [\App\Http\Controllers\Admin\AuditLogController::class, 'index'])->middleware('can:audit.view')->name('audit.index');
    });

    // HR & Payroll (Phase 7)
    Route::prefix('hr')->name('hr.')->group(function () {
        Route::get('/', fn () => redirect()->route('hr.employees.index'))->name('index');

        Route::get('employees', [EmployeeController::class, 'index'])->middleware('can:hr.employee.view')->name('employees.index');
        Route::post('employees', [EmployeeController::class, 'store'])->middleware('can:hr.employee.manage')->name('employees.store');
        Route::put('employees/{employee}', [EmployeeController::class, 'update'])->middleware('can:hr.employee.manage')->name('employees.update');

        Route::get('attendance', [AttendanceController::class, 'index'])->middleware('can:hr.attendance.manage')->name('attendance.index');
        Route::post('attendance', [AttendanceController::class, 'store'])->middleware('can:hr.attendance.manage')->name('attendance.store');

        Route::get('payroll', [PayrollRunController::class, 'index'])->middleware('can:hr.payroll.run')->name('payroll.index');
        Route::get('payroll/statutory-report', [PayrollRunController::class, 'statutory'])->middleware('can:hr.payroll.run')->name('payroll.statutory');
        Route::get('payroll/settings', [PayrollSettingController::class, 'index'])->middleware('can:hr.payroll.configure')->name('payroll.settings');
        Route::put('payroll/settings', [PayrollSettingController::class, 'update'])->middleware('can:hr.payroll.configure')->name('payroll.settings.update');
        Route::get('payroll/create', [PayrollRunController::class, 'create'])->middleware('can:hr.payroll.run')->name('payroll.create');
        Route::post('payroll', [PayrollRunController::class, 'store'])->middleware('can:hr.payroll.run')->name('payroll.store');
        Route::get('payroll/{run}', [PayrollRunController::class, 'show'])->middleware('can:hr.payroll.run')->name('payroll.show');
        Route::get('payroll/{run}/payslip/{payslip}', [PayrollRunController::class, 'payslip'])->middleware('can:hr.payroll.run')->name('payroll.payslip');
        Route::post('payroll/{run}/post', [PayrollRunController::class, 'post'])->middleware('can:hr.payroll.run')->name('payroll.post');
        Route::post('payroll/{run}/reverse', [PayrollRunController::class, 'reverse'])->middleware('can:hr.payroll.run')->name('payroll.reverse');
        Route::post('payroll/{run}/pay', [PayrollRunController::class, 'pay'])->middleware('can:hr.payroll.pay')->name('payroll.pay');
        Route::post('payroll-payments/{payment}/reverse', [PayrollRunController::class, 'reversePayment'])->middleware('can:hr.payroll.pay')->name('payroll.payments.reverse');
    });
});

require __DIR__.'/settings.php';
