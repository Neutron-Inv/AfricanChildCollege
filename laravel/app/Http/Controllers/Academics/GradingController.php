<?php

namespace App\Http\Controllers\Academics;

use App\Http\Controllers\Concerns\RunsAcademicsActions;
use App\Http\Controllers\Controller;
use App\Services\AcademicsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GradingController extends Controller
{
    use RunsAcademicsActions;

    public function update(Request $request, AcademicsService $academics): RedirectResponse
    {
        $data = $request->validate([
            'bands' => ['required', 'array', 'min:1', 'max:20'],
            'bands.*.min_score' => ['required', 'integer', 'between:0,100'],
            'bands.*.max_score' => ['required', 'integer', 'between:0,100'],
            'bands.*.letter' => ['required', 'string', 'max:4'],
            'bands.*.remark' => ['required', 'string', 'max:40'],
        ]);

        return $this->attempt(fn () => $academics->replaceBands($data['bands']), 'Grading scale updated.');
    }
}
