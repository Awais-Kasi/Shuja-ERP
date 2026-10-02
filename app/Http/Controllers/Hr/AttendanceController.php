<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    private const STATUSES = ['present', 'absent', 'leave', 'half_day', 'holiday'];

    public function index(Request $request): Response
    {
        $date = $request->date('date')?->toDateString() ?: now()->toDateString();

        $employees = Employee::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'department']);

        $records = AttendanceRecord::whereDate('attendance_date', $date)
            ->get()
            ->keyBy('employee_id');

        $rows = $employees->map(fn (Employee $e) => [
            'employee_id' => $e->id,
            'code' => $e->code,
            'name' => $e->name,
            'department' => $e->department,
            'status' => $records->get($e->id)?->status ?? 'present',
            'remarks' => $records->get($e->id)?->remarks,
        ]);

        return Inertia::render('hr/attendance/index', [
            'date' => $date,
            'rows' => $rows,
            'statuses' => self::STATUSES,
            'summary' => [
                'present' => $rows->where('status', 'present')->count(),
                'absent' => $rows->where('status', 'absent')->count(),
                'leave' => $rows->where('status', 'leave')->count(),
            ],
        ]);
    }

    public function store(Request $request, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('company_id', $tenant->id())],
            'rows.*.status' => ['required', Rule::in(self::STATUSES)],
            'rows.*.remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $date = Carbon::parse($validated['date'])->toDateString();

        foreach ($validated['rows'] as $row) {
            AttendanceRecord::updateOrCreate(
                [
                    'company_id' => $tenant->id(),
                    'employee_id' => $row['employee_id'],
                    'attendance_date' => $date,
                ],
                [
                    'status' => $row['status'],
                    'remarks' => $row['remarks'] ?? null,
                ],
            );
        }

        return back()->with('success', "Attendance saved for {$date}.");
    }
}
