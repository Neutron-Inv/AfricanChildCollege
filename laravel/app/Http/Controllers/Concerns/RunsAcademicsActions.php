<?php

namespace App\Http\Controllers\Concerns;

use Closure;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

trait RunsAcademicsActions
{
    /**
     * Run a service call; turn rule violations (DomainException) into an error toast / JSON message.
     *
     * @param  array<string, mixed>|Closure|null  $to  academics query params (or a closure of the result returning them); null = back
     * @param  Closure|null  $json  when the client expects JSON, build the payload from the service result
     */
    protected function attempt(Closure $action, string $success, array|Closure|null $to = null, ?Closure $json = null): RedirectResponse|JsonResponse
    {
        try {
            $result = $action();
        } catch (DomainException $e) {
            if (request()->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->withInput()->with('error', $e->getMessage());
        }

        if (request()->expectsJson()) {
            $payload = $json ? $json($result) : [];

            return response()->json(['message' => $success] + $payload);
        }

        if ($to instanceof Closure) {
            $to = $to($result);
        }

        $redirect = $to === null ? back() : redirect()->route('academics', $to);

        return $redirect->with('success', $success);
    }
}
