@php
    $fmt = fn ($v) => $v === null || $v === '' ? '—' : (string) (int) round((float) $v);
    $pct = fn ($v) => $v === null || $v === '' ? '—' : number_format((float) $v, 2, '.', '');
    $student = $enrolment->student;
    $report = $card['report'];
    $cumulative = (bool) ($card['cumulative'] ?? false);
    $domains = \App\Enums\ReportTrait::grouped();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Report card — {{ $student?->full_name }} — {{ $term->name }}</title>
    <style>
        @page { margin: 7mm 8mm 32mm 8mm; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 8.5pt;
            line-height: 1.2;
            color: #1f2937;
            margin: 0;
            padding: 0;
        }
        .header { border-bottom: 2px solid {{ $primary }}; padding-bottom: 4px; margin-bottom: 6px; }
        .header-table { width: 100%; border-collapse: collapse; }
        .header-table td { vertical-align: middle; }
        .logo { width: 40px; height: 40px; }
        .logo-fallback {
            width: 36px; height: 36px;
            border: 1.5px solid {{ $primary }};
            border-radius: 4px;
            text-align: center;
            line-height: 33px;
            font-size: 13pt;
            font-weight: bold;
            color: {{ $primary }};
        }
        .school-name { font-size: 13pt; font-weight: bold; color: {{ $primary }}; margin: 0; line-height: 1.15; }
        .motto { font-style: italic; color: #6b7280; margin: 0; font-size: 8pt; }
        .meta { color: #4b5563; margin: 1px 0 0; line-height: 1.25; font-size: 8pt; }
        .doc-title { text-align: right; }
        .doc-title h1 {
            margin: 0;
            font-size: 12pt;
            color: #111827;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .doc-title p { margin: 1px 0 0; color: #6b7280; font-size: 8pt; }
        .identity { width: 100%; border-collapse: collapse; margin-bottom: 5px; }
        .identity td { vertical-align: top; padding: 0; }
        .student-name { font-size: 12pt; font-weight: bold; margin: 0; line-height: 1.2; }
        img.star { width: 11px; height: 11px; vertical-align: middle; }
        .muted { color: #6b7280; margin: 0; font-size: 8pt; }
        .summary { text-align: right; font-size: 8.5pt; }
        .summary strong { font-size: 11pt; }
        table.sheet { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.sheet th {
            background: {{ $primary }};
            color: #ffffff;
            padding: 2px 3px;
            border: 1px solid {{ $primary }};
            font-weight: 600;
            text-align: center;
            font-size: 7.5pt;
            line-height: 1.2;
        }
        table.sheet th.subject, table.sheet td.subject { text-align: left; white-space: nowrap; }
        table.sheet td {
            border: 1px solid #d1d5db;
            padding: 1px 3px;
            text-align: center;
            font-size: 8pt;
            line-height: 1.3;
        }
        table.sheet tbody tr:nth-child(even) td { background: #f9fafb; }
        table.sheet tfoot td {
            border: 1px solid #d1d5db;
            padding: 2px 3px;
            font-weight: 700;
            background: #f3f4f6;
            font-size: 8pt;
        }
        .num { font-variant-numeric: tabular-nums; }
        .section { margin-top: 6px; }
        .section h2 {
            margin: 0 0 2px 0;
            font-size: 8.5pt;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: {{ $primary }};
        }
        table.facts { width: 100%; border-collapse: collapse; }
        table.facts td { padding: 0 8px 0 0; font-size: 8pt; }
        table.facts td.label { color: #6b7280; width: 16%; }
        table.comments { width: 100%; border-collapse: collapse; }
        table.comments td { width: 50%; vertical-align: top; padding: 0; }
        table.comments td + td { padding-left: 6px; }
        .comment {
            border: 1px solid #e5e7eb;
            padding: 4px 8px;
        }
        .comment .label { color: #6b7280; font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.03em; }
        .comment .body { margin-top: 2px; }
        .grades { color: #4b5563; font-size: 7.5pt; margin: 4px 0 10px; line-height: 1.25; }
        table.domains { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 0; }
        table.domains > tbody > tr > td {
            width: 50%;
            vertical-align: top;
            border: 1px solid #e5e7eb;
            padding: 3px 6px 2px;
            background: #fafafa;
        }
        .domain-title {
            font-size: 8pt;
            font-weight: 700;
            color: {{ $primary }};
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin: 0 0 2px 0;
        }
        table.trait { width: 100%; border-collapse: collapse; }
        table.trait td { padding: 1px 0; vertical-align: middle; }
        table.trait td.trait-label { font-size: 8pt; }
        table.pips { border-collapse: separate; border-spacing: 2px 0; margin-left: auto; }
        table.pips td {
            width: 11px;
            height: 11px;
            line-height: 11px;
            text-align: center;
            font-size: 6.5pt;
            color: #9ca3af;
            border: 1px solid #d1d5db;
            background: #ffffff;
        }
        table.pips td.filled {
            background: {{ $primary }};
            border-color: {{ $primary }};
            color: #ffffff;
            font-weight: 700;
        }
        .scale-note { margin: 2px 0 0; color: #6b7280; font-size: 7pt; line-height: 1.25; }
        .sign-block {
            position: fixed;
            right: 0;
            bottom: -20mm;
            width: 62mm;
            text-align: center;
        }
        .sign-img { height: 36px; margin-bottom: 1px; }
        .sign-line {
            border-top: 1px solid #9ca3af;
            margin: 0 8mm;
            padding-top: 2px;
            color: #4b5563;
            font-size: 7.5pt;
        }
        .footer {
            position: fixed;
            bottom: -28mm;
            left: 0;
            right: 0;
            font-size: 6.5pt;
            color: #9ca3af;
            border-top: 1px solid #e5e7eb;
            padding-top: 2px;
        }
        .footer-table { width: 100%; }
    </style>
</head>
<body>
    <div class="header">
        <table class="header-table">
            <tr>
                <td style="width: 44px;">
                    @if ($logoDataUri)
                        <img class="logo" src="{{ $logoDataUri }}" alt="School logo">
                    @else
                        <div class="logo-fallback">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($school->name, 0, 1)) }}</div>
                    @endif
                </td>
                <td style="padding-left: 8px;">
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
                <td class="doc-title" style="width: 34%;">
                    <h1>Report card</h1>
                    <p>{{ $term->session?->name }} · {{ $term->name }}</p>
                    <p>{{ $generatedAt->timezone(config('app.timezone'))->format('d M Y') }}</p>
                </td>
            </tr>
        </table>
    </div>

    <table class="identity">
        <tr>
            <td>
                <p class="student-name">
                    @if (($card['position'] ?? null) === 1)
                        <img class="star" src="{{ $starDataUri }}" width="11" height="11" alt="Highest total">
                    @endif
                    {{ $student?->full_name }}
                </p>
                <p class="muted">
                    {{ $student?->index_number ?: '—' }}
                    · {{ $classLabel }}
                    @if ($student?->gender) · {{ $student->gender }} @endif
                </p>
            </td>
            <td class="summary">
                <div>{{ $cumulative ? 'Cumulative percentage' : 'Average' }} <strong>{{ $cumulative ? $pct($card['average']) : $fmt($card['average']) }}</strong></div>
                <div>Position <strong>{{ $card['position'] ? $card['position'].' of '.$card['classSize'] : '—' }}</strong></div>
                <div class="muted">Total {{ $card['count'] ? $fmt($card['sum']) : '—' }} · {{ $card['count'] }} {{ \Illuminate\Support\Str::plural('subject', $card['count']) }}</div>
            </td>
        </tr>
    </table>

    <div class="section" style="margin-top: 4px; margin-bottom: 2px;">
        <h2>Attendance</h2>
        <table class="facts">
            <tr>
                <td class="label">Days present</td>
                <td>{{ $report?->days_present ?? 0 }}</td>
                <td class="label">Days absent</td>
                <td>{{ $report?->days_absent ?? 0 }}</td>
                <td class="label">School open</td>
                <td>{{ (int) $term->days_school_open ?: '—' }}</td>
            </tr>
        </table>
    </div>

    <table class="sheet">
        <thead>
            <tr>
                <th class="subject">Subject</th>
                <th>CA1</th>
                <th>CA2</th>
                <th>CA3</th>
                <th>Exam</th>
                <th>{{ $cumulative ? '3rd' : 'Total' }}</th>
                @if ($cumulative)
                    <th>1st</th>
                    <th>2nd</th>
                    <th>Session</th>
                @endif
                <th>Grade</th>
                <th>Remark</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($card['lines'] as $line)
                @php $s = $line['score']; @endphp
                <tr>
                    <td class="subject">{{ $line['subject'] }}</td>
                    @if ($s?->absent)
                        <td colspan="4">Absent</td>
                    @else
                        <td class="num">{{ $fmt($s?->ca1) }}</td>
                        <td class="num">{{ $fmt($s?->ca2) }}</td>
                        <td class="num">{{ $fmt($s?->ca3) }}</td>
                        <td class="num">{{ $fmt($s?->exam_score) }}</td>
                    @endif
                    <td class="num"><strong>{{ $fmt($line['total']) }}</strong></td>
                    @if ($cumulative)
                        <td class="num">{{ $fmt($line['terms'][1] ?? null) }}</td>
                        <td class="num">{{ $fmt($line['terms'][2] ?? null) }}</td>
                        <td class="num"><strong>{{ $fmt($line['aggregate'] ?? null) }}</strong></td>
                    @endif
                    <td><strong>{{ $line['grade']['letter'] ?? '—' }}</strong></td>
                    <td>{{ $line['grade']['remark'] ?? '' }}</td>
                </tr>
            @empty
                <tr><td class="subject" colspan="{{ $cumulative ? 11 : 8 }}">No subjects for this class.</td></tr>
            @endforelse
        </tbody>
        @if ($card['count'])
            <tfoot>
                <tr>
                    <td class="subject" colspan="5">{{ $cumulative ? 'Session total' : 'Total' }}</td>
                    @if ($cumulative)
                        <td class="num">{{ $fmt($card['termSum'] ?? null) }}</td>
                        <td colspan="2"></td>
                        <td class="num">{{ $fmt($card['sum']) }}</td>
                    @else
                        <td class="num">{{ $fmt($card['sum']) }}</td>
                    @endif
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        @endif
    </table>

    @if ($cumulative)
        {{-- <p class="scale-note">1st and 2nd are this session's earlier totals. Cumulative percentage is the average of the Session column, to two decimal places. A term with no score, or where the student was absent, is left out.</p> --}}
    @endif

    @if ($bands->isNotEmpty())
        <p class="grades">
            Grades:
            @foreach ($bands as $band)
                {{ $band['letter'] }} {{ $band['min'] }}–{{ $band['max'] }}
                @if ($band['remark'])
                    ({{ $band['remark'] }})
                @endif
                @if (! $loop->last)
                    ·
                @endif
            @endforeach
        </p>
    @endif

    <div class="section" style="margin-top: 8px;">
            <h2>Behaviour</h2>
            <table class="domains">
                <tr>
                    @foreach ($domains as $domain => $traits)
                        <td>
                            <p class="domain-title">{{ $domain }}</p>
                            @foreach ($traits as $key => $label)
                                @php $rating = (int) ($report?->score($key) ?? 0); @endphp
                                <table class="trait">
                                    <tr>
                                        <td class="trait-label">{{ $label }}</td>
                                        <td>
                                            <table class="pips">
                                                <tr>
                                                    @for ($mark = 1; $mark <= 5; $mark++)
                                                        <td @class(['filled' => $rating >= $mark])>{{ $mark }}</td>
                                                    @endfor
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </table>
                            @endforeach
                        </td>
                    @endforeach
                </tr>
            </table>
            <p class="scale-note">Each quality is rated from 1 to 5. A filled box is the score reached.</p>
        </div>

    <div class="section">
        <h2>Comments</h2>
        <table class="comments">
            <tr>
                <td>
                    <div class="comment">
                        <div class="label">Teacher's comment</div>
                        <div class="body">{{ $report?->teacher_comment }}</div>
                    </div>
                </td>
                <td>
                    <div class="comment">
                        <div class="label">Principal's comment</div>
                        <div class="body">{{ $report?->principal_comment }}</div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="sign-block">
        @if ($signatureDataUri ?? null)
            <img class="sign-img" src="{{ $signatureDataUri }}" alt="Principal's signature">
        @endif
        <div class="sign-line">Principal's signature</div>
    </div>

    <div class="footer">
        <table class="footer-table">
            <tr>
                <td>{{ $school->name }} · Confidential school record</td>
                <td style="text-align: right;">{{ $student?->full_name }} · {{ $classLabel }} · {{ $term->session?->name }} {{ $term->name }}</td>
            </tr>
        </table>
    </div>
</body>
</html>
