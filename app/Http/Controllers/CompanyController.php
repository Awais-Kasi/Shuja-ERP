<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    /**
     * Switch the active company for the current session.
     */
    public function switch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer'],
        ]);

        $user = $request->user();
        $companyId = (int) $validated['company_id'];

        if ($user->is_super_admin || $user->belongsToCompany($companyId)) {
            $request->session()->put('current_company_id', $companyId);

            return back()->with('success', 'Active company switched.');
        }

        return back()->with('error', 'You do not have access to that company.');
    }
}
