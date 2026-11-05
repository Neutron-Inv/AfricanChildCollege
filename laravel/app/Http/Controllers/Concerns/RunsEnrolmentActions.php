<?php

namespace App\Http\Controllers\Concerns;

use Closure;
use DomainException;
use Illuminate\Http\RedirectResponse;

trait RunsEnrolmentActions
{
    /**
     * Run a service call; turn rule violations (DomainException) into an error toast.
     *
     * @param  Closure|null  $to  receives the result and returns the redirect; null = back
     */
    protected function attempt(Closure $action, string|Closure $success, ?Closure $to = null): RedirectResponse
    {
        try {
            $result = $action();
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $redirect = $to ? $to($result) : back();

        return $redirect->with('success', $success instanceof Closure ? $success($result) : $success);
    }
}
