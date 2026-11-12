<?php

use App\Http\Controllers\Academics\AcademicsController;
use App\Http\Controllers\Academics\CatalogueController;
use App\Http\Controllers\Academics\ClassController;
use App\Http\Controllers\Academics\ClassSubjectController;
use App\Http\Controllers\Academics\GradingController;
use App\Http\Controllers\Academics\SessionController;
use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EnrolmentController;
use App\Http\Controllers\ExamsController;
use App\Http\Controllers\LessonController;
use App\Http\Controllers\NoticeController;
use App\Http\Controllers\FeeItemController;
use App\Http\Controllers\FeesController;
use App\Http\Controllers\GuardianController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PeopleController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\Platform\SchoolController;
use App\Http\Controllers\SchoolLogoController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TimetableController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(Auth::check() ? 'dashboard' : 'login'));

// Login / logout / password reset (registration is disabled).
require __DIR__.'/auth.php';

Route::middleware('auth')->group(function () {
    // Available to every signed-in user, including the forced first-login flow.
    Route::get('/change-password', [ChangePasswordController::class, 'edit'])->name('password.change');
    Route::put('/change-password', [ChangePasswordController::class, 'update'])->name('password.change.update');

    // Platform host: platform owner only.
    Route::middleware(['role:PLATFORM_OWNER', 'password.changed'])->prefix('platform')->name('platform.')->group(function () {
        Route::get('/schools', [SchoolController::class, 'index'])->name('schools');
        Route::post('/schools/{school}/enter', [SchoolController::class, 'enter'])->name('schools.enter');
        Route::post('/leave', [SchoolController::class, 'leave'])->name('leave');
    });

    // School shell: resolved school (host, or entered by the platform owner).
    Route::middleware(['school', 'password.changed'])->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/branding/logo', SchoolLogoController::class)->name('branding.logo');

        // People: staff-side read access; students edited by ADMIN/REGISTRAR; staff created by ADMIN.
        Route::middleware('role:ADMIN,REGISTRAR,TEACHER,PLATFORM_OWNER')->get('/people', [PeopleController::class, 'index'])->name('people');

        Route::middleware('role:ADMIN,REGISTRAR,PLATFORM_OWNER')->group(function () {
            Route::post('/people/students', [StudentController::class, 'store'])->name('people.students.store');
            Route::put('/people/students/{student}', [StudentController::class, 'update'])->name('people.students.update');
            Route::delete('/people/students/{student}', [StudentController::class, 'destroy'])->name('people.students.destroy');
            Route::post('/people/students/{student}/guardians', [StudentController::class, 'storeGuardian'])->name('people.students.guardians.store');
        });

        Route::middleware('role:ADMIN,PLATFORM_OWNER')->group(function () {
            Route::post('/people/staff', [StaffController::class, 'store'])->name('people.staff.store');
            Route::put('/people/staff/{staff}', [StaffController::class, 'update'])->name('people.staff.update');
            Route::put('/people/guardians/{guardian}/password', [GuardianController::class, 'password'])->name('people.guardians.password');
        });

        // Academics: staff can view; ADMIN / REGISTRAR edit; grading bands are ADMIN only.
        Route::middleware('role:ADMIN,REGISTRAR,TEACHER,PLATFORM_OWNER')->group(function () {
            Route::get('/academics', [AcademicsController::class, 'index'])->name('academics');
            Route::get('/academics/classes/{class}/panel', [ClassController::class, 'panel'])->name('academics.classes.panel');
        });

        Route::middleware('role:ADMIN,REGISTRAR,PLATFORM_OWNER')->prefix('academics')->name('academics.')->group(function () {
            Route::post('/sessions', [SessionController::class, 'store'])->name('sessions.store');
            Route::put('/sessions/{session}', [SessionController::class, 'update'])->name('sessions.update');
            Route::delete('/sessions/{session}', [SessionController::class, 'destroy'])->name('sessions.destroy');
            Route::post('/sessions/{session}/current', [SessionController::class, 'current'])->name('sessions.current');
            Route::post('/terms', [SessionController::class, 'storeTerm'])->name('terms.store');

            Route::post('/classes', [ClassController::class, 'store'])->name('classes.store');
            Route::put('/classes/{class}', [ClassController::class, 'update'])->name('classes.update');
            Route::put('/classes/{class}/form-teacher', [ClassController::class, 'updateFormTeacher'])->name('classes.form-teacher');
            Route::put('/classes/{class}/subjects', [ClassSubjectController::class, 'sync'])->name('classes.subjects.sync');
            Route::post('/classes/{class}/subjects', [ClassSubjectController::class, 'store'])->name('class-subjects.store');
            Route::put('/class-subjects/{classSubject}/kind', [ClassSubjectController::class, 'updateKind'])->name('class-subjects.kind');
            Route::put('/class-subjects/{classSubject}/teacher', [ClassSubjectController::class, 'updateTeacher'])->name('class-subjects.teacher');
            Route::delete('/class-subjects/{classSubject}', [ClassSubjectController::class, 'destroy'])->name('class-subjects.destroy');

            Route::post('/subjects', [CatalogueController::class, 'storeSubject'])->name('subjects.store');
            Route::put('/subjects/{subject}', [CatalogueController::class, 'updateSubject'])->name('subjects.update');
            Route::post('/departments', [CatalogueController::class, 'storeDepartment'])->name('departments.store');
            Route::post('/rooms', [CatalogueController::class, 'storeRoom'])->name('rooms.store');
        });

        // Enrolment: ADMIN / REGISTRAR only.
        Route::middleware('role:ADMIN,REGISTRAR,PLATFORM_OWNER')->prefix('enrolment')->name('enrolment.')->group(function () {
            Route::get('/promote', [PromotionController::class, 'create'])->name('promote');
            Route::post('/promote', [PromotionController::class, 'store'])->name('promote.store');
            Route::post('/place', [EnrolmentController::class, 'place'])->name('place');
            Route::put('/{enrolment}/class', [EnrolmentController::class, 'move'])->name('move');
            Route::post('/{enrolment}/leave', [EnrolmentController::class, 'leave'])->name('leave');
            Route::post('/{enrolment}/active', [EnrolmentController::class, 'active'])->name('active');
        });
        Route::get('/enrolment', [EnrolmentController::class, 'index'])
            ->middleware('role:ADMIN,REGISTRAR,TEACHER,PLATFORM_OWNER')
            ->name('enrolment');
        Route::put('/enrolment/{enrolment}/electives', [EnrolmentController::class, 'electives'])
            ->middleware('role:ADMIN,REGISTRAR,TEACHER,PLATFORM_OWNER')
            ->name('enrolment.electives');

        Route::put('/academics/grading', [GradingController::class, 'update'])
            ->middleware('role:ADMIN,PLATFORM_OWNER')
            ->name('academics.grading.update');

        // Timetable: everyone signed in can view (students/parents see only their classes);
        // ADMIN / REGISTRAR manage periods.
        Route::middleware('role:ADMIN,REGISTRAR,TEACHER,STUDENT,PARENT,PLATFORM_OWNER')
            ->get('/timetable', [TimetableController::class, 'index'])->name('timetable');

        Route::middleware('role:ADMIN,REGISTRAR,PLATFORM_OWNER')->prefix('timetable')->name('timetable.')->group(function () {
            Route::post('/structure', [TimetableController::class, 'structure'])->name('structure');
            Route::post('/assignments', [TimetableController::class, 'assign'])->name('assignments');
            Route::post('/periods', [TimetableController::class, 'store'])->name('periods.store');
            Route::put('/periods/{period}', [TimetableController::class, 'update'])->name('periods.update');
            Route::delete('/periods/{period}', [TimetableController::class, 'destroy'])->name('periods.destroy');
        });

        // Attendance: students/parents see their own history; the register is for the office and form teachers
        // (the service re-checks form-teacher ownership of the class).
        Route::middleware('role:ADMIN,REGISTRAR,ACCOUNTANT,TEACHER,STUDENT,PARENT,PLATFORM_OWNER')
            ->get('/attendance', [AttendanceController::class, 'index'])->name('attendance');
        Route::middleware('role:ADMIN,REGISTRAR,ACCOUNTANT,TEACHER,PLATFORM_OWNER')
            ->post('/attendance', [AttendanceController::class, 'store'])->name('attendance.store');

        // Fees: the registrar can view; parents see only their children's invoices;
        // ADMIN / ACCOUNTANT manage fee items, generate invoices and record payments.
        Route::middleware('role:ADMIN,ACCOUNTANT,REGISTRAR,PARENT,PLATFORM_OWNER')
            ->get('/fees', [FeesController::class, 'index'])->name('fees');

        Route::middleware('role:ADMIN,ACCOUNTANT,PLATFORM_OWNER')->prefix('fees')->name('fees.')->group(function () {
            Route::post('/items', [FeeItemController::class, 'store'])->name('items.store');
            Route::put('/items/{feeItem}', [FeeItemController::class, 'update'])->name('items.update');
            Route::post('/invoices/generate', [InvoiceController::class, 'generate'])->name('invoices.generate');
            Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');
            Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
            Route::post('/invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('payments.store');
        });

        // Lesson notes: teachers write them for their class subjects; the subject's HOD / ADMIN sign them off
        // (the service re-checks authorship and HOD ownership).
        Route::middleware('role:ADMIN,TEACHER,PLATFORM_OWNER')->prefix('lessons')->name('lessons')->group(function () {
            Route::get('/', [LessonController::class, 'index']);
            Route::get('/create', [LessonController::class, 'create'])->name('.create');
            Route::post('/', [LessonController::class, 'store'])->name('.store');
            Route::get('/{lesson}', [LessonController::class, 'show'])->name('.show');
            Route::get('/{lesson}/edit', [LessonController::class, 'edit'])->name('.edit');
            Route::put('/{lesson}', [LessonController::class, 'update'])->name('.update');
            Route::post('/{lesson}/submit', [LessonController::class, 'submit'])->name('.submit');
            Route::post('/{lesson}/approve', [LessonController::class, 'approve'])->name('.approve');
            Route::post('/{lesson}/return', [LessonController::class, 'returnToDraft'])->name('.return');
        });

        // Assignments: teachers manage their own; students / parents see their classes' and download the file.
        Route::middleware('role:ADMIN,TEACHER,STUDENT,PARENT,PLATFORM_OWNER')->prefix('assignments')->name('assignments')->group(function () {
            Route::get('/', [AssignmentController::class, 'index']);
            Route::post('/', [AssignmentController::class, 'store'])->name('.store');
            Route::get('/{assignment}/file', [AssignmentController::class, 'file'])->name('.file');
            Route::delete('/{assignment}', [AssignmentController::class, 'destroy'])->name('.destroy');
        });

        // Notices + school calendar: everyone reads; ADMIN / REGISTRAR post.
        Route::get('/notices', [NoticeController::class, 'index'])->name('notices');
        Route::middleware('role:ADMIN,REGISTRAR,PLATFORM_OWNER')->prefix('notices')->name('notices.')->group(function () {
            Route::post('/', [NoticeController::class, 'store'])->name('store');
            Route::post('/events', [NoticeController::class, 'storeEvent'])->name('events.store');
            Route::delete('/events/{event}', [NoticeController::class, 'destroyEvent'])->name('events.destroy');
            Route::delete('/{notice}', [NoticeController::class, 'destroy'])->name('destroy');
        });

        // Exams: the office edits every subject; teachers only the subjects they teach (the service
        // re-checks ownership); students/parents read their own report cards.
        Route::middleware('role:ADMIN,REGISTRAR,TEACHER,STUDENT,PARENT,PLATFORM_OWNER')
            ->get('/exams', [ExamsController::class, 'index'])->name('exams');
        Route::middleware('role:ADMIN,REGISTRAR,TEACHER,PLATFORM_OWNER')
            ->get('/exams/broadsheet.pdf', [ExamsController::class, 'broadsheetPdf'])->name('exams.broadsheet.pdf');
        Route::middleware('role:ADMIN,REGISTRAR,TEACHER,STUDENT,PARENT,PLATFORM_OWNER')
            ->get('/exams/report.pdf', [ExamsController::class, 'reportPdf'])->name('exams.report.pdf');
        Route::middleware('role:ADMIN,REGISTRAR,TEACHER,PLATFORM_OWNER')
            ->post('/exams/scores', [ExamsController::class, 'saveScores'])->name('exams.scores.store');
        Route::middleware('role:ADMIN,REGISTRAR,TEACHER,PLATFORM_OWNER')
            ->put('/exams/report', [ExamsController::class, 'saveReport'])->name('exams.report.update');

        Route::middleware('role:ADMIN,PLATFORM_OWNER')->group(function () {
            Route::get('/settings', [SettingsController::class, 'show'])->name('settings');
            Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
        });
    });
});
