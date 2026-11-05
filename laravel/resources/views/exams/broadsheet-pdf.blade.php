@php
    $fmt = fn ($v) => $v === null || $v === '' ? '—' : (string) (int) round((float) $v);
    $teacherName = $formTeacher
        ? trim($formTeacher->first_name.' '.$formTeacher->last_name)
        : null;
    $subjectCount = $subjectCount ?? $sheet['subjects']->count();
    $starDataUri = app(\App\Services\ExamsService::class)->highestStarDataUri();
    $studentCount = $sheet['rows']->count();
    // Scale type as columns grow so content stays inside the (possibly widened) page.
    $fontSize = $subjectCount > 18 ? '6.5pt' : ($subjectCount > 12 ? '7.5pt' : ($subjectCount > 8 ? '8.5pt' : '9pt'));
    // Letters that fit across one header cell. Whole words fill row 1, then row 2.
    $perLine = $subjectCount > 24 ? 11 : ($subjectCount > 14 ? 13 : 16);
    $subjectHeads = $sheet['subjects']->map(function ($cs) use ($perLine) {
        $words = preg_split('/\s+/', trim((string) $cs->subject?->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $rows = ['', ''];
        $row = 0;

        foreach ($words as $word) {
            $current = $rows[$row];
            $candidate = $current === '' ? $word : $current.' '.$word;

            if (mb_strlen($candidate) <= $perLine) {
                $rows[$row] = $candidate;

                continue;
            }

            if ($current === '') {
                if ($row === 0) {
                    $rows[0] = mb_substr($word, 0, $perLine);
                    $rest = mb_substr($word, $perLine);
                    $rows[1] = $rest === '' ? '' : (mb_strlen($rest) <= $perLine ? $rest : rtrim(mb_substr($rest, 0, $perLine - 1)).'…');
                } else {
                    $rows[1] = rtrim(mb_substr($word, 0, $perLine - 1)).'…';
                }
                break;
            }

            if ($row === 1) {
                $rows[1] = (mb_strlen($rows[1]) >= $perLine ? rtrim(mb_substr($rows[1], 0, $perLine - 1)) : $rows[1]).'…';
                break;
            }

            $row = 1;
            $rows[1] = mb_strlen($word) <= $perLine ? $word : rtrim(mb_substr($word, 0, $perLine - 1)).'…';
        }

        return $rows;
    });
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Broadsheet — {{ $classLabel }} — {{ $term->name }}</title>
    <style>
        @page { margin: 8mm 8mm 14mm 8mm; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: {{ $fontSize }};
            color: #1f2937;
            margin: 0;
            padding: 0;
        }
        .header {
            border-bottom: 2.5px solid {{ $primary }};
            padding-bottom: 8px;
            margin-bottom: 8px;
        }
        .header-table { width: 100%; border-collapse: collapse; }
        .header-table td { vertical-align: middle; }
        .logo {
            width: 52px;
            height: 52px;
        }
        .logo-fallback {
            width: 52px;
            height: 52px;
            border: 2px solid {{ $primary }};
            border-radius: 6px;
            text-align: center;
            line-height: 48px;
            font-size: 18pt;
            font-weight: bold;
            color: {{ $primary }};
        }
        .school-name {
            font-size: 14pt;
            font-weight: bold;
            color: {{ $primary }};
            margin: 0 0 2px 0;
        }
        .motto {
            font-style: italic;
            color: #6b7280;
            margin: 0 0 3px 0;
        }
        .meta { color: #4b5563; margin: 0; line-height: 1.3; }
        .doc-title { text-align: right; }
        .doc-title h1 {
            margin: 0;
            font-size: 11pt;
            color: #111827;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .doc-title p { margin: 2px 0 0; color: #6b7280; }
        .info-bar {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
        }
        .info-bar th, .info-bar td {
            padding: 4px 6px;
            text-align: left;
            border-right: 1px solid #e5e7eb;
        }
        .info-bar th {
            width: 14%;
            color: #6b7280;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 6.5pt;
            letter-spacing: 0.03em;
        }
        .info-bar td:last-child, .info-bar th:last-child { border-right: none; }
        table.sheet {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        table.sheet thead th {
            background: {{ $primary }};
            color: #ffffff;
            padding: 3px 2px;
            border: 1px solid {{ $primary }};
            font-weight: 600;
            text-align: center;
            vertical-align: middle;
            line-height: 1.2;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        table.sheet thead th.sub {
            padding: 2px 1px;
            font-size: 6pt;
            white-space: nowrap;
        }
        body.for-screen { overflow-x: auto; }
        body.for-screen table.sheet { table-layout: auto; width: max-content; min-width: 100%; }
        body.for-screen table.sheet thead th.sub,
        body.for-screen table.sheet tbody td.meta-col { min-width: 3.4rem; }
        body.for-screen table.sheet thead th.name,
        body.for-screen table.sheet tbody td.name { width: auto; min-width: 8.5rem; }
        table.sheet thead th.name,
        table.sheet tbody td.name {
            text-align: left;
            padding-left: 4px;
            width: {{ $subjectCount > 14 ? '16%' : '20%' }};
        }
        table.sheet thead th.meta-col,
        table.sheet tbody td.meta-col {
            width: {{ max(3.2, 28 / max($subjectCount + 4, 1)) }}%;
        }
        table.sheet thead th.idx,
        table.sheet tbody td.idx { width: 18px; }
        table.sheet tbody td {
            border: 1px solid #d1d5db;
            padding: 2px 1px;
            text-align: center;
            word-wrap: break-word;
            overflow-wrap: anywhere;
        }
        table.sheet tbody td.name { font-weight: 600; }
        table.sheet tbody tr:nth-child(even) td { background: #f9fafb; }
        table.sheet tbody tr.top td { background: #fffbeb; }
        img.star { width: 9px; height: 9px; vertical-align: middle; }
        .num { font-variant-numeric: tabular-nums; }
        .strong { font-weight: 700; }
        .sign-block {
            margin-top: 14px;
            width: 100%;
            border-collapse: collapse;
        }
        .sign-block td {
            width: 33%;
            vertical-align: bottom;
            padding: 0 10px;
            text-align: center;
        }
        .sign-img {
            height: 36px;
            margin-bottom: 2px;
        }
        .sign-line {
            border-top: 1px solid #9ca3af;
            margin-top: 28px;
            padding-top: 3px;
            color: #4b5563;
            font-size: 7pt;
        }
        .footer {
            position: fixed;
            bottom: -6mm;
            left: 0;
            right: 0;
            font-size: 6.5pt;
            color: #9ca3af;
            border-top: 1px solid #e5e7eb;
            padding-top: 3px;
        }
        .footer-table { width: 100%; }
        .note { margin-top: 6px; color: #6b7280; font-size: 6.5pt; }
        .empty { padding: 24px; text-align: center; color: #6b7280; }
    </style>
</head>
<body @class(['for-screen' => $forScreen ?? false])>
    <div class="header">
        <table class="header-table">
            <tr>
                <td style="width: 60px;">
                    @if ($logoDataUri)
                        <img class="logo" src="{{ $logoDataUri }}" alt="School logo">
                    @else
                        <div class="logo-fallback">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($school->name, 0, 1)) }}</div>
                    @endif
                </td>
                <td style="padding-left: 10px;">
                    <p class="school-name">{{ $school->name }}</p>
                    @if ($school->motto)
                        <p class="motto">“{{ $school->motto }}”</p>
                    @endif
                    <p class="meta">
                        @if ($school->address) {{ $school->address }} @endif
                        @if ($school->phone) · {{ $school->phone }} @endif
                        @if ($school->email) · {{ $school->email }} @endif
                    </p>
                </td>
                <td class="doc-title" style="width: 30%;">
                    <h1>Class Broadsheet</h1>
                    <p>Examination summary · totals / {{ \App\Services\ExamsService::MAX_TOTAL }}</p>
                    <p>{{ $generatedAt->timezone(config('app.timezone'))->format('d M Y · H:i') }}</p>
                </td>
            </tr>
        </table>
    </div>

    <table class="info-bar">
        <tr>
            <th>Class</th>
            <td>{{ $classLabel }}</td>
            <th>Session</th>
            <td>{{ $term->session?->name }}</td>
            <th>Term</th>
            <td>{{ $term->name }}</td>
        </tr>
        <tr>
            <th>Form teacher</th>
            <td>{{ $teacherName ?: '—' }}</td>
            <th>Students</th>
            <td>{{ $studentCount }}</td>
            <th>Subjects</th>
            <td>{{ $subjectCount }}@if (! empty($sheet['limited'])) · your subjects only @endif</td>
        </tr>
    </table>

    @if ($sheet['subjects']->isEmpty() || $sheet['rows']->isEmpty())
        <div class="empty">No broadsheet data for this class and term.</div>
    @else
        <table class="sheet">
            <thead>
                <tr>
                    <th class="idx" rowspan="2">#</th>
                    <th class="name" rowspan="2">Student</th>
                    @foreach ($subjectHeads as $lines)
                        <th class="meta-col sub">{{ $lines[0] }}</th>
                    @endforeach
                    <th class="meta-col" rowspan="2">Total</th>
                    <th class="meta-col" rowspan="2">Avg</th>
                    <th class="meta-col" rowspan="2">Pos.</th>
                </tr>
                <tr>
                    @foreach ($subjectHeads as $lines)
                        <th class="meta-col sub">{{ $lines[1] !== '' ? $lines[1] : ' ' }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($sheet['rows'] as $i => $row)
                    <tr @class(['top' => ($row['position'] ?? null) === 1])>
                        <td class="idx num">{{ $i + 1 }}</td>
                        <td class="name">
                            @if (($row['position'] ?? null) === 1)
                                <img class="star" src="{{ $starDataUri }}" width="9" height="9" alt="Highest total">
                            @endif
                            {{ $row['student']?->full_name }}
                        </td>
                        @foreach ($sheet['subjects'] as $cs)
                            <td class="num meta-col">{{ $fmt($row['cells'][$cs->id] ?? null) }}</td>
                        @endforeach
                        <td class="num strong meta-col">{{ $row['count'] ? $fmt($row['sum']) : '—' }}</td>
                        <td class="num meta-col">{{ $fmt($row['average']) }}</td>
                        <td class="num strong meta-col">{{ $row['position'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p class="note">
            Position uses competition ranking (ties share a place). Blank cells mean no mark recorded for that subject.
            @if (! empty($sheet['limited']))
                This export is limited to subjects assigned to the signed-in teacher.
            @endif
        </p>

        <table class="sign-block">
            <tr>
                <td>
                    <div class="sign-line">Form teacher{{ $teacherName ? ' · '.$teacherName : '' }}</div>
                </td>
                <td>
                    @if ($signatureDataUri ?? null)
                        <img class="sign-img" src="{{ $signatureDataUri }}" alt="Principal signature">
                    @endif
                    <div class="sign-line" @if ($signatureDataUri ?? null) style="margin-top: 2px;" @endif>Principal</div>
                </td>
                <td>
                    <div class="sign-line">Date</div>
                </td>
            </tr>
        </table>
    @endif

    <div class="footer">
        <table class="footer-table">
            <tr>
                <td>{{ $school->name }} · Confidential school record</td>
                <td style="text-align: center;">Generated by {{ $generatedBy }}</td>
                <td style="text-align: right;">{{ $term->session?->name }} · {{ $subjectCount }} subjects</td>
            </tr>
        </table>
    </div>
</body>
</html>
