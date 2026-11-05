import Alpine from 'alpinejs';

window.Alpine = Alpine;

/**
 * JSON fetch with CSRF for in-page Academics actions (no full reload).
 * Throws Error with a readable message on non-2xx responses.
 */
window.schoolFetch = async function schoolFetch(url, options = {}) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
        ...(options.headers || {}),
    };

    let body = options.body;
    if (body !== undefined && body !== null && !(body instanceof FormData) && typeof body !== 'string') {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(body);
    }

    const response = await fetch(url, { ...options, headers, body, credentials: 'same-origin' });
    const payload = await response.json().catch(() => ({}));

    if (! response.ok) {
        const message = payload.message
            || (payload.errors && Object.values(payload.errors).flat()[0])
            || `Request failed (${response.status})`;
        throw new Error(message);
    }

    return payload;
};

document.addEventListener('alpine:init', () => {
    Alpine.store('toasts', {
        items: [],
        push(type, message) {
            if (! message) {
                return;
            }
            const id = Date.now() + Math.random();
            this.items.push({ id, type, message });
            setTimeout(() => this.dismiss(id), 5000);
        },
        dismiss(id) {
            this.items = this.items.filter((t) => t.id !== id);
        },
        success(message) {
            this.push('success', message);
        },
        error(message) {
            this.push('error', message);
        },
    });

    Alpine.data('academicsClasses', (config) => ({
        canEdit: config.canEdit,
        sessionId: config.sessionId,
        academicsUrl: config.academicsUrl,
        panelUrlTemplate: config.panelUrlTemplate,
        syncUrlTemplate: config.syncUrlTemplate,
        storeSubjectUrlTemplate: config.storeSubjectUrlTemplate,
        destroySubjectUrlTemplate: config.destroySubjectUrlTemplate,
        teachers: config.teachers,
        subjectsCatalogue: config.subjectsCatalogue,
        classes: config.classes,
        classId: config.classId,
        panel: config.panel,
        saving: false,
        assigning: false,
        loadingPanel: false,
        showAssign: false,
        subjectFilter: 'all',
        subjectQuery: '',
        assign: { subject_id: '', teacher_id: '', kind: 'COMPULSORY' },
        snapshot: '',

        init() {
            this.snapshot = this.serialize();
            window.addEventListener('popstate', () => {
                const params = new URLSearchParams(window.location.search);
                const id = params.get('class');
                if (id && id !== this.classId) {
                    this.selectClass(id, false);
                } else if (! id && this.classId) {
                    this.classId = null;
                    this.panel = null;
                }
            });
        },

        serialize() {
            if (! this.panel) {
                return '';
            }

            return JSON.stringify({
                form_teacher_id: this.panel.form_teacher_id || '',
                subjects: (this.panel.subjects || []).map((s) => ({
                    id: s.id,
                    kind: s.kind,
                    teacher_id: s.teacher_id || '',
                })),
            });
        },

        get dirty() {
            return this.panel && this.serialize() !== this.snapshot;
        },

        get availableSubjects() {
            const taken = new Set((this.panel?.subjects || []).map((s) => s.subject_id));

            return this.subjectsCatalogue.filter((s) => ! taken.has(s.id));
        },

        get compulsoryCount() {
            return (this.panel?.subjects || []).filter((s) => s.kind === 'COMPULSORY').length;
        },

        get electiveCount() {
            return (this.panel?.subjects || []).filter((s) => s.kind === 'ELECTIVE').length;
        },

        get visibleSubjects() {
            const query = this.subjectQuery.trim().toLowerCase();

            return (this.panel?.subjects || []).filter((row) => {
                if (this.subjectFilter !== 'all' && row.kind !== this.subjectFilter) {
                    return false;
                }
                if (query && ! (row.subject_name || '').toLowerCase().includes(query)) {
                    return false;
                }

                return true;
            });
        },

        setKind(row, kind) {
            row.kind = kind;
        },

        teacherLabel(id) {
            if (! id) {
                return 'Unassigned';
            }
            const t = this.teachers.find((row) => row.id === id);

            return t ? t.name : 'Unassigned';
        },

        panelUrl(classId) {
            return this.panelUrlTemplate.replace('__CLASS__', classId);
        },

        syncUrl(classId) {
            return this.syncUrlTemplate.replace('__CLASS__', classId);
        },

        storeSubjectUrl(classId) {
            return this.storeSubjectUrlTemplate.replace('__CLASS__', classId);
        },

        destroySubjectUrl(id) {
            return this.destroySubjectUrlTemplate.replace('__ID__', id);
        },

        async selectClass(classId, push = true) {
            if (this.dirty && ! confirm('You have unsaved changes. Discard them?')) {
                return;
            }

            this.loadingPanel = true;
            try {
                const data = await window.schoolFetch(this.panelUrl(classId));
                this.classId = classId;
                this.panel = data;
                this.snapshot = this.serialize();
                this.assign = { subject_id: '', teacher_id: '', kind: 'COMPULSORY' };
                this.subjectFilter = 'all';
                this.subjectQuery = '';
                this.showAssign = false;

                if (push) {
                    const url = new URL(this.academicsUrl, window.location.origin);
                    url.searchParams.set('tab', 'classes');
                    url.searchParams.set('session', this.sessionId);
                    url.searchParams.set('class', classId);
                    window.history.pushState({}, '', url);
                }
            } catch (error) {
                Alpine.store('toasts').error(error.message);
            } finally {
                this.loadingPanel = false;
            }
        },

        applyPanel(data) {
            this.panel = data;
            this.snapshot = this.serialize();
            const card = this.classes.find((c) => c.id === data.id);
            if (card) {
                card.form_teacher_name = data.form_teacher_name;
                card.form_teacher_id = data.form_teacher_id;
                card.subjects_count = (data.subjects || []).length;
            }
        },

        async save() {
            if (! this.panel || this.saving) {
                return;
            }

            this.saving = true;
            try {
                const data = await window.schoolFetch(this.syncUrl(this.panel.id), {
                    method: 'PUT',
                    body: {
                        form_teacher_id: this.panel.form_teacher_id || null,
                        subjects: (this.panel.subjects || []).map((s) => ({
                            id: s.id,
                            kind: s.kind,
                            teacher_id: s.teacher_id || null,
                        })),
                    },
                });
                this.applyPanel(data.panel);
                Alpine.store('toasts').success(data.message || 'Class saved.');
            } catch (error) {
                Alpine.store('toasts').error(error.message);
            } finally {
                this.saving = false;
            }
        },

        async assignSubject() {
            if (! this.panel || this.assigning || ! this.assign.subject_id) {
                return;
            }

            this.assigning = true;
            try {
                const data = await window.schoolFetch(this.storeSubjectUrl(this.panel.id), {
                    method: 'POST',
                    body: {
                        subject_id: this.assign.subject_id,
                        teacher_id: this.assign.teacher_id || null,
                        kind: this.assign.kind,
                    },
                });
                this.applyPanel(data.panel);
                this.assign = { subject_id: '', teacher_id: '', kind: 'COMPULSORY' };
                Alpine.store('toasts').success(data.message || 'Subject added.');
            } catch (error) {
                Alpine.store('toasts').error(error.message);
            } finally {
                this.assigning = false;
            }
        },

        async removeSubject(subject) {
            if (! this.panel) {
                return;
            }
            if (this.dirty) {
                Alpine.store('toasts').error('Save your edits before removing a subject.');
                return;
            }

            const name = subject.subject_name || 'this subject';
            if (! confirm(`Remove ${name} from ${this.panel.name}? This also removes its student enrolments, scores, timetable periods, lesson notes and assignments for this class. This cannot be undone.`)) {
                return;
            }

            try {
                const data = await window.schoolFetch(this.destroySubjectUrl(subject.id), { method: 'DELETE' });
                this.applyPanel(data.panel);
                Alpine.store('toasts').success(data.message || 'Subject removed.');
            } catch (error) {
                Alpine.store('toasts').error(error.message);
            }
        },
    }));

    Alpine.data('academicsSubjects', (config) => ({
        canEdit: config.canEdit,
        academicsUrl: config.academicsUrl,
        updateUrlTemplate: config.updateUrlTemplate,
        subjects: config.subjects,
        departments: config.departments,
        selectedId: config.selectedId,
        sessionId: config.sessionId,
        query: '',
        departmentFilter: 'all',
        draft: { name: '', department_id: '' },
        snapshot: '',
        saving: false,

        init() {
            const initial = this.subjects.some((s) => s.id === this.selectedId) ? this.selectedId : null;
            if (initial) {
                this.loadDraft(initial);
            }
        },

        get filtered() {
            const query = this.query.trim().toLowerCase();

            return this.subjects.filter((subject) => {
                if (this.departmentFilter === 'none' && subject.department_id) {
                    return false;
                }
                if (this.departmentFilter !== 'all' && this.departmentFilter !== 'none' && subject.department_id !== this.departmentFilter) {
                    return false;
                }
                if (query && ! subject.name.toLowerCase().includes(query)) {
                    return false;
                }

                return true;
            });
        },

        get selected() {
            return this.subjects.find((subject) => subject.id === this.selectedId) || null;
        },

        get dirty() {
            return this.selected && JSON.stringify(this.draft) !== this.snapshot;
        },

        loadDraft(id) {
            const subject = this.subjects.find((row) => row.id === id);
            if (! subject) {
                this.selectedId = null;
                return;
            }
            this.selectedId = id;
            this.draft = { name: subject.name, department_id: subject.department_id || '' };
            this.snapshot = JSON.stringify(this.draft);
        },

        select(id, push = true) {
            if (this.dirty && ! confirm('You have unsaved changes. Discard them?')) {
                return;
            }
            this.loadDraft(id);
            if (push && this.selectedId) {
                const url = new URL(this.academicsUrl, window.location.origin);
                url.searchParams.set('tab', 'subjects');
                url.searchParams.set('subject', this.selectedId);
                if (this.sessionId) {
                    url.searchParams.set('session', this.sessionId);
                }
                window.history.pushState({}, '', url);
            }
        },

        async save() {
            if (! this.selected || this.saving) {
                return;
            }
            this.saving = true;
            try {
                const data = await window.schoolFetch(this.updateUrlTemplate.replace('__ID__', this.selected.id), {
                    method: 'PUT',
                    body: {
                        name: this.draft.name,
                        department_id: this.draft.department_id || null,
                        session_id: this.sessionId || null,
                    },
                });
                const index = this.subjects.findIndex((row) => row.id === this.selected.id);
                if (index >= 0) {
                    this.subjects.splice(index, 1, data.subject);
                    this.subjects.sort((a, b) => a.name.localeCompare(b.name));
                }
                this.loadDraft(data.subject.id);
                Alpine.store('toasts').success(data.message || 'Subject saved.');
            } catch (error) {
                Alpine.store('toasts').error(error.message);
            } finally {
                this.saving = false;
            }
        },
    }));
});

Alpine.start();
