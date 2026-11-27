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
        updateUrlTemplate: config.updateUrlTemplate,
        destroyClassUrlTemplate: config.destroyClassUrlTemplate,
        levels: config.levels || [],
        arms: config.arms || [],
        editingDetails: false,
        savingDetails: false,
        detailBackup: null,
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
        pending: [],
        askDelete: false,
        deletePhrase: '',
        deleting: false,
        snapshot: '',

        init() {
            this.pending = [this.blankRow()];
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

        blankRow() {
            return { key: `${Date.now()}-${Math.random()}`, subject_id: '', teacher_id: '', kind: 'COMPULSORY' };
        },

        get hasPendingSubject() {
            return this.pending.some((row) => row.subject_id);
        },

        get pendingCount() {
            return this.pending.filter((row) => row.subject_id).length;
        },

        get anotherSubjectAvailable() {
            const taken = this.takenSubjectIds();

            return (this.subjectsCatalogue || []).some((subject) => ! taken.has(subject.id));
        },

        takenSubjectIds(exceptIndex = null) {
            const taken = new Set((this.panel?.subjects || []).map((subject) => subject.subject_id));
            this.pending.forEach((row, index) => {
                if (index !== exceptIndex && row.subject_id) {
                    taken.add(row.subject_id);
                }
            });

            return taken;
        },

        subjectsFor(index) {
            const taken = this.takenSubjectIds(index);
            const current = this.pending[index]?.subject_id;

            return (this.subjectsCatalogue || []).filter((subject) => subject.id === current || ! taken.has(subject.id));
        },

        addPendingRow() {
            if (! this.anotherSubjectAvailable) {
                return;
            }
            this.pending.push(this.blankRow());
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

        updateUrl(classId) {
            return this.updateUrlTemplate.replace('__CLASS__', classId);
        },

        destroyClassUrl(classId) {
            return this.destroyClassUrlTemplate.replace('__CLASS__', classId);
        },

        startDetails() {
            this.detailBackup = {
                level_id: this.panel.level_id,
                arm_id: this.panel.arm_id,
                admission_fee: this.panel.admission_fee,
                hall_charge: this.panel.hall_charge,
            };
            this.editingDetails = true;
        },

        cancelDetails() {
            if (this.detailBackup && this.panel) {
                Object.assign(this.panel, this.detailBackup);
            }
            this.editingDetails = false;
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
                this.pending = [this.blankRow()];
                this.cancelDelete();
                this.subjectFilter = 'all';
                this.subjectQuery = '';
                this.showAssign = false;
                this.editingDetails = false;

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
                card.name = data.name;
                card.form_teacher_name = data.form_teacher_name;
                card.form_teacher_id = data.form_teacher_id;
                card.subjects_count = (data.subjects || []).length;
            }
        },

        async saveDetails() {
            if (! this.panel || this.savingDetails) {
                return;
            }

            this.savingDetails = true;
            try {
                const data = await window.schoolFetch(this.updateUrl(this.panel.id), {
                    method: 'PUT',
                    body: {
                        level_id: this.panel.level_id,
                        arm_id: this.panel.arm_id,
                        admission_fee: this.panel.admission_fee,
                        hall_charge: this.panel.hall_charge,
                    },
                });
                this.applyPanel(data.panel);
                this.editingDetails = false;
                Alpine.store('toasts').success(data.message || 'Class saved.');
            } catch (error) {
                Alpine.store('toasts').error(error.message);
            } finally {
                this.savingDetails = false;
            }
        },

        async persistPanel() {
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

            return data;
        },

        async save() {
            if (! this.panel || this.saving) {
                return;
            }

            this.saving = true;
            try {
                const data = await this.persistPanel();
                Alpine.store('toasts').success(data.message || 'Class saved.');
            } catch (error) {
                Alpine.store('toasts').error(error.message);
            } finally {
                this.saving = false;
            }
        },

        async assignSubjects() {
            const rows = this.pending.filter((row) => row.subject_id);
            if (! this.panel || this.assigning || ! rows.length) {
                return;
            }

            this.assigning = true;
            try {
                if (this.dirty) {
                    await this.persistPanel();
                }
                const data = await window.schoolFetch(this.storeSubjectUrl(this.panel.id), {
                    method: 'POST',
                    body: {
                        subjects: rows.map((row) => ({
                            subject_id: row.subject_id,
                            teacher_id: row.teacher_id || null,
                            kind: row.kind,
                        })),
                    },
                });
                this.applyPanel(data.panel);
                this.pending = [this.blankRow()];
                Alpine.store('toasts').success(data.message || 'Subjects added.');
            } catch (error) {
                Alpine.store('toasts').error(error.message);
            } finally {
                this.assigning = false;
            }
        },

        askToDelete() {
            if (! this.panel) {
                return;
            }
            this.deletePhrase = '';
            this.askDelete = true;
            this.$nextTick(() => this.$refs.deleteInput?.focus());
        },

        cancelDelete() {
            this.askDelete = false;
            this.deletePhrase = '';
        },

        async confirmDelete() {
            if (! this.panel || this.deleting || this.deletePhrase.trim().toLowerCase() !== 'delete') {
                return;
            }

            this.deleting = true;
            const classId = this.panel.id;
            try {
                const data = await window.schoolFetch(this.destroyClassUrl(classId), { method: 'DELETE' });
                this.classes = this.classes.filter((card) => card.id !== classId);
                this.panel = null;
                this.classId = null;
                this.editingDetails = false;
                this.cancelDelete();
                const url = new URL(this.academicsUrl, window.location.origin);
                url.searchParams.set('tab', 'classes');
                url.searchParams.set('session', this.sessionId);
                window.history.pushState({}, '', url);
                Alpine.store('toasts').success(data.message || 'Class deleted.');
            } catch (error) {
                Alpine.store('toasts').error(error.message);
            } finally {
                this.deleting = false;
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
        destroySubjectUrlTemplate: config.destroySubjectUrlTemplate,
        storeClassUrlTemplate: config.storeClassUrlTemplate,
        destroyClassUrlTemplate: config.destroyClassUrlTemplate,
        subjects: config.subjects,
        departments: config.departments,
        classes: config.classes || [],
        teachers: config.teachers || [],
        selectedId: config.selectedId,
        sessionId: config.sessionId,
        query: '',
        departmentFilter: 'all',
        draft: { name: '', department_id: '' },
        snapshot: '',
        offeringSnapshot: '',
        saving: false,
        adding: false,
        showAdd: false,
        pending: [],
        askDelete: false,
        deletePhrase: '',
        deleting: false,

        init() {
            this.pending = [this.blankRow()];
            const initial = this.subjects.some((s) => s.id === this.selectedId) ? this.selectedId : null;
            if (initial) {
                this.loadDraft(initial);
            }
        },

        blankRow() {
            return { key: `${Date.now()}-${Math.random()}`, class_id: '', teacher_id: '', kind: 'COMPULSORY' };
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
            return this.selected && (JSON.stringify(this.draft) !== this.snapshot || this.serializeOfferings() !== this.offeringSnapshot);
        },

        get hasPendingClass() {
            return this.pending.some((row) => row.class_id);
        },

        get pendingCount() {
            return this.pending.filter((row) => row.class_id).length;
        },

        get anotherClassAvailable() {
            const taken = this.takenClassIds();

            return this.classes.some((schoolClass) => ! taken.has(schoolClass.id));
        },

        takenClassIds(exceptIndex = null) {
            const taken = new Set((this.selected?.offerings || []).map((offer) => offer.class_id));
            this.pending.forEach((row, index) => {
                if (index !== exceptIndex && row.class_id) {
                    taken.add(row.class_id);
                }
            });

            return taken;
        },

        classesFor(index) {
            const taken = this.takenClassIds(index);
            const current = this.pending[index]?.class_id;

            return this.classes.filter((schoolClass) => schoolClass.id === current || ! taken.has(schoolClass.id));
        },

        addPendingRow() {
            if (! this.anotherClassAvailable) {
                return;
            }
            this.pending.push(this.blankRow());
        },

        serializeOfferings() {
            return JSON.stringify((this.selected?.offerings || []).map((offer) => ({
                id: offer.id,
                kind: offer.kind,
                teacher_id: offer.teacher_id || '',
            })));
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
            this.offeringSnapshot = this.serializeOfferings();
        },

        replaceSubject(subject) {
            const index = this.subjects.findIndex((row) => row.id === subject.id);
            if (index >= 0) {
                this.subjects.splice(index, 1, subject);
                this.subjects.sort((a, b) => a.name.localeCompare(b.name));
            }
            this.loadDraft(subject.id);
        },

        select(id, push = true) {
            if (this.dirty && ! confirm('You have unsaved changes. Discard them?')) {
                return;
            }
            this.loadDraft(id);
            this.pending = [this.blankRow()];
            this.showAdd = false;
            this.cancelDelete();
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

        async persistSubject() {
            const data = await window.schoolFetch(this.updateUrlTemplate.replace('__ID__', this.selected.id), {
                method: 'PUT',
                body: {
                    name: this.draft.name,
                    department_id: this.draft.department_id || null,
                    session_id: this.sessionId || null,
                    offerings: (this.selected.offerings || []).map((offer) => ({
                        id: offer.id,
                        kind: offer.kind,
                        teacher_id: offer.teacher_id || null,
                    })),
                },
            });
            this.replaceSubject(data.subject);

            return data;
        },

        async save() {
            if (! this.selected || this.saving) {
                return;
            }
            this.saving = true;
            try {
                const data = await this.persistSubject();
                Alpine.store('toasts').success(data.message || 'Subject saved.');
            } catch (error) {
                Alpine.store('toasts').error(error.message);
            } finally {
                this.saving = false;
            }
        },

        async addClasses() {
            const rows = this.pending.filter((row) => row.class_id);
            if (! this.selected || this.adding || ! rows.length) {
                return;
            }

            this.adding = true;
            try {
                if (this.dirty) {
                    await this.persistSubject();
                }
                const data = await window.schoolFetch(this.storeClassUrlTemplate.replace('__ID__', this.selected.id), {
                    method: 'POST',
                    body: {
                        session_id: this.sessionId || null,
                        classes: rows.map((row) => ({
                            class_id: row.class_id,
                            teacher_id: row.teacher_id || null,
                            kind: row.kind,
                        })),
                    },
                });
                this.replaceSubject(data.subject);
                this.pending = [this.blankRow()];
                Alpine.store('toasts').success(data.message || 'Classes added.');
            } catch (error) {
                Alpine.store('toasts').error(error.message);
            } finally {
                this.adding = false;
            }
        },

        askToDelete() {
            if (! this.selected) {
                return;
            }
            this.deletePhrase = '';
            this.askDelete = true;
            this.$nextTick(() => this.$refs.deleteInput?.focus());
        },

        cancelDelete() {
            this.askDelete = false;
            this.deletePhrase = '';
        },

        async confirmDelete() {
            if (! this.selected || this.deleting || this.deletePhrase.trim().toLowerCase() !== 'delete') {
                return;
            }

            this.deleting = true;
            const subjectId = this.selected.id;
            try {
                const data = await window.schoolFetch(this.destroySubjectUrlTemplate.replace('__ID__', subjectId), { method: 'DELETE' });
                this.subjects = this.subjects.filter((subject) => subject.id !== subjectId);
                this.selectedId = null;
                this.pending = [this.blankRow()];
                this.showAdd = false;
                this.cancelDelete();
                const url = new URL(this.academicsUrl, window.location.origin);
                url.searchParams.set('tab', 'subjects');
                if (this.sessionId) {
                    url.searchParams.set('session', this.sessionId);
                }
                window.history.pushState({}, '', url);
                Alpine.store('toasts').success(data.message || 'Subject deleted.');
            } catch (error) {
                Alpine.store('toasts').error(error.message);
            } finally {
                this.deleting = false;
            }
        },

        async removeClass(offer) {
            if (! this.selected || ! offer) {
                return;
            }
            if (this.dirty) {
                Alpine.store('toasts').error('Save your edits before removing a class.');
                return;
            }
            const name = offer.class_name || 'this class';
            if (! confirm(`Remove ${this.selected.name} from ${name}? Students in that class will no longer take it.`)) {
                return;
            }

            try {
                const url = this.destroyClassUrlTemplate
                    .replace('__ID__', this.selected.id)
                    .replace('__CLASS_SUBJECT__', offer.id);
                const data = await window.schoolFetch(url, {
                    method: 'DELETE',
                    body: { session_id: this.sessionId || null },
                });
                this.replaceSubject(data.subject);
                Alpine.store('toasts').success(data.message || 'Class removed.');
            } catch (error) {
                Alpine.store('toasts').error(error.message);
            }
        },
    }));
});

Alpine.start();
