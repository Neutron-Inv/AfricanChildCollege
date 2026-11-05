<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Services\FeesService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeesController extends Controller
{
    public function __construct(private readonly FeesService $fees) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $canManage = $this->fees->canManage($user);
        $canViewAll = $this->fees->canViewAll($user);

        // Parents only get their children's invoices; no catalogue and no generator.
        $tabs = ['invoices' => 'Invoices'];
        if ($canViewAll) {
            $tabs['items'] = 'Fee items';
        }
        if ($canManage) {
            $tabs['generate'] = 'Generate invoices';
        }

        $tab = $request->query('tab', 'invoices');
        if (! array_key_exists($tab, $tabs)) {
            $tab = 'invoices';
        }

        $filters = $request->only(['term_id', 'class_id', 'status', 'q']);
        $terms = $this->fees->terms();
        $classes = $canViewAll ? $this->fees->classes() : new \Illuminate\Database\Eloquent\Collection;

        $data = [
            'tab' => $tab,
            'tabs' => $tabs,
            'canManage' => $canManage,
            'canViewAll' => $canViewAll,
            'isParent' => $user->hasRole(Role::PARENT),
            'terms' => $terms,
            'classes' => $classes,
            'statuses' => FeesService::STATUSES,
            'methods' => FeesService::METHODS,
            'filters' => $filters,
        ];

        if ($tab === 'invoices') {
            $data['invoices'] = $this->fees->invoices($user, $filters);
            $data['totals'] = $this->fees->totals($user, $filters);
        } elseif ($tab === 'items') {
            $data['items'] = $this->fees->feeItems();
            $data['sessions'] = $this->fees->sessions();
        } else {
            $data['currentSession'] = $this->fees->currentSession();
            $data['defaultTerm'] = $terms->firstWhere('session_id', $data['currentSession']?->id);
        }

        return view('fees.index', $data);
    }
}
