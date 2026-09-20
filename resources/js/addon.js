/**
 * Komponent Eksport — the Control Panel utility.
 *
 * Plain JS, no build step: the file is published as it is to
 * public/vendor/component-exporter/js/ and loaded by Statamic. It is one Vue
 * component built from Statamic's own UI kit (window.__STATAMIC__.ui), so it
 * follows the Control Panel's light and dark themes. Its own styling is a few
 * layout rules with colours mixed from currentColor and the CP's
 * `--color-primary`, so boxes and selected tiles look right in both themes.
 *
 * Routes are resolved relative to the utility's own URL, so the addon need not
 * know what the Control Panel is called or where it is mounted.
 *
 * Two panels, one thing at a time: export (sections by group, then collections,
 * forms, globals and loose blueprints as units; what travels along; download)
 * and import (upload, review file by file, apply).
 */
(function () {
    'use strict';

    const ui = (window.__STATAMIC__ && window.__STATAMIC__.ui) || {};

    const ROLE = {
        fieldset: 'Fieldset',
        partial: 'Partial',
        css: 'Tailwind',
        preview: 'Preview',
        config: 'Konfiguration',
        blueprint: 'Blueprint',
        view: 'View',
        template: 'Skabelon',
        preset: 'Preset',
        collection: 'Collection',
        file: 'Fil',
    };

    const STATUS = {
        new: { text: 'Ny', color: 'green' },
        same: { text: 'Uændret', color: 'default' },
        changed: { text: 'Ændret', color: 'amber' },
    };

    // The unit kinds, in the order they are shown.
    const KINDS = [
        { key: 'collections', title: 'Collections', hint: 'Konfiguration, blueprint, index- og show-views, skabeloner og presets følger med.', one: 'collection', many: 'collections' },
        { key: 'forms', title: 'Formularer', hint: 'Formularens opsætning og blueprint.', one: 'formular', many: 'formularer' },
        { key: 'globals', title: 'Globals', hint: 'Opsætning og blueprint — ikke værdierne.', one: 'global', many: 'globals' },
        { key: 'blueprints', title: 'Øvrige blueprints', hint: 'Assets, brugere og standard-blueprintet.', one: 'blueprint', many: 'blueprints' },
    ];

    const STYLE = `
.ce{--ce-line:color-mix(in srgb,currentColor 12%,transparent);--ce-fill:color-mix(in srgb,currentColor 4%,transparent);--ce-fill-2:color-mix(in srgb,currentColor 7%,transparent);--ce-accent:var(--color-primary,#4f46e5);--ce-accent-soft:color-mix(in srgb,var(--color-primary,#4f46e5) 14%,transparent);display:grid;gap:1.5rem;max-width:64rem}
.ce-area+.ce-area{margin-top:1.75rem;padding-top:1.5rem;border-top:1px solid var(--ce-line)}
.ce-area-head{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-bottom:.75rem}
.ce-area-head .ce-hint{flex:1 1 16rem}
.ce-box{background:var(--ce-fill);border:1px solid var(--ce-line);border-radius:.75rem;padding:.75rem .9rem}
.ce-box+.ce-box{margin-top:.75rem}
.ce-box-head{display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-bottom:.6rem}
.ce-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(16rem,1fr));gap:.5rem}
.ce-tiles-wide{grid-template-columns:repeat(auto-fill,minmax(21rem,1fr))}
.ce-tile{display:flex;gap:.6rem;align-items:flex-start;padding:.6rem .7rem;border:1px solid var(--ce-line);border-radius:.6rem;background:color-mix(in srgb,currentColor 2%,transparent);cursor:pointer;transition:border-color .12s,background .12s}
.ce-tile:hover{border-color:color-mix(in srgb,currentColor 28%,transparent)}
.ce-tile.is-on{border-color:var(--ce-accent);background:var(--ce-accent-soft)}
.ce-tile-body{min-width:0;flex:1}
.ce-tile-title{font-weight:500;font-size:.875rem;line-height:1.25rem}
.ce-tile-meta{font-size:.75rem;opacity:.75;margin-top:.1rem}
.ce-tags{display:flex;gap:.25rem;flex-wrap:wrap;margin-top:.35rem}
.ce-files summary{font-size:.75rem;cursor:pointer;margin-top:.4rem;opacity:.8}
.ce-rows{margin-top:.25rem}
.ce-row{display:grid;grid-template-columns:auto minmax(0,1fr) auto auto;gap:.6rem;align-items:center;padding:.35rem 0;border-top:1px solid var(--ce-line);font-size:.8rem}
.ce-row-2{grid-template-columns:auto minmax(0,1fr)}
.ce-row-3{grid-template-columns:auto minmax(0,1fr) auto}
.ce-rows>.ce-row:first-child{border-top:0}
.ce-cell{min-width:0;display:flex;flex-direction:column;gap:.1rem}
.ce-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.75rem;overflow-wrap:anywhere}
.ce-bar{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-top:1.5rem;padding:.75rem .9rem;border-radius:.75rem;background:var(--ce-fill-2)}
.ce-actions{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap}
.ce-title{display:flex;align-items:baseline;gap:.5rem;flex-wrap:wrap}
.ce-list{margin:0;padding-inline-start:1rem}
.ce-file{font-size:.875rem}
.ce-empty{font-size:.8rem;opacity:.7;padding:.25rem 0}
`;

    const base = () => window.location.pathname.replace(/\/$/, '');
    const plural = (n, one, many) => n + ' ' + (n === 1 ? one : many);

    const Utility = {
        name: 'ComponentExporterUtility',

        components: {
            UiCardPanel: ui.CardPanel,
            UiHeading: ui.Heading,
            UiSubheading: ui.Subheading,
            UiDescription: ui.Description,
            UiText: ui.Text,
            UiBadge: ui.Badge,
            UiButton: ui.Button,
            UiCheckbox: ui.Checkbox,
            UiAlert: ui.Alert,
        },

        props: {
            token: { type: String, default: '' },
        },

        data() {
            return {
                kinds: KINDS,
                loading: true,
                error: '',
                groups: [],
                units: { collections: [], forms: [], globals: [], blueprints: [] },
                selected: [],
                selUnits: [],
                exporting: false,
                exportMsg: '',
                exportOk: true,
                importFile: null,
                importStep: 'select', // select | review | done
                review: null,
                writeFiles: {},
                registerSections: {},
                importing: false,
                importMsg: '',
                importOk: true,
            };
        },

        computed: {
            sections() {
                return this.groups.flatMap(g => g.sections);
            },
            allUnits() {
                return KINDS.flatMap(k => this.units[k.key] || []);
            },
            selectedThings() {
                return [
                    ...this.sections.filter(s => this.selected.includes(s.handle)),
                    ...this.allUnits.filter(u => this.selUnits.includes(u.id)),
                ];
            },
            // Shared files across everything selected, each with what uses it.
            deps() {
                const map = new Map();
                this.selectedThings.forEach(t => (t.shared || []).forEach(f => {
                    if (!map.has(f.path)) map.set(f.path, { ...f, usedBy: [] });
                    map.get(f.path).usedBy.push(t.display);
                }));
                return [...map.values()].sort((a, b) => a.role.localeCompare(b.role) || a.label.localeCompare(b.label));
            },
            missing() {
                const out = new Set();
                this.selectedThings.forEach(t => (t.missing || []).forEach(m => out.add(t.display + ' → ' + m)));
                return [...out];
            },
            total() {
                return this.selected.length + this.selUnits.length;
            },
            summary() {
                const parts = [];
                if (this.selected.length) parts.push(plural(this.selected.length, 'sektion', 'sektioner'));
                KINDS.forEach(k => {
                    const n = (this.units[k.key] || []).filter(u => this.selUnits.includes(u.id)).length;
                    if (n) parts.push(plural(n, k.one, k.many));
                });
                return parts.join(' · ');
            },
            reviewWriteCount() {
                return Object.values(this.writeFiles).filter(Boolean).length;
            },
            reviewRegisterCount() {
                return Object.values(this.registerSections).filter(Boolean).length;
            },
        },

        mounted() {
            if (!document.getElementById('ce-styles')) {
                const style = document.createElement('style');
                style.id = 'ce-styles';
                style.textContent = STYLE;
                document.head.appendChild(style);
            }

            this.load();
        },

        methods: {
            async json(path, options = {}) {
                const res = await fetch(base() + path, options);
                const data = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(data.error || ('Fejl ' + res.status));
                return data;
            },

            post(body) {
                return {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.token },
                    body: JSON.stringify(body),
                };
            },

            async load() {
                try {
                    const data = await this.json('/items');
                    this.groups = data.groups || [];
                    this.units = { collections: [], forms: [], globals: [], blueprints: [], ...(data.units || {}) };
                    const known = new Set(this.sections.map(s => s.handle));
                    this.selected = (data.selection?.page_sections || []).filter(h => known.has(h));
                } catch (e) {
                    this.error = 'Kunne ikke indlæse: ' + e.message;
                } finally {
                    this.loading = false;
                }
            },

            isSelected(handle) {
                return this.selected.includes(handle);
            },

            async toggleSection(handle) {
                const i = this.selected.indexOf(handle);
                i === -1 ? this.selected.push(handle) : this.selected.splice(i, 1);
                await this.json('/selection/toggle', this.post({ handle })).catch(() => {});
            },

            groupSelected(group) {
                return group.sections.length > 0 && group.sections.every(s => this.isSelected(s.handle));
            },

            async toggleGroup(group) {
                const on = !this.groupSelected(group);
                const handles = group.sections.map(s => s.handle);
                this.selected = on
                    ? [...new Set([...this.selected, ...handles])]
                    : this.selected.filter(h => !handles.includes(h));
                await this.json('/selection/toggle', this.post({ set: this.selected })).catch(() => {});
            },

            isUnit(id) {
                return this.selUnits.includes(id);
            },

            toggleUnit(id) {
                const i = this.selUnits.indexOf(id);
                i === -1 ? this.selUnits.push(id) : this.selUnits.splice(i, 1);
            },

            kindSelected(kind) {
                const list = this.units[kind.key] || [];
                return list.length > 0 && list.every(u => this.isUnit(u.id));
            },

            toggleKind(kind) {
                const ids = (this.units[kind.key] || []).map(u => u.id);
                this.selUnits = this.kindSelected(kind)
                    ? this.selUnits.filter(id => !ids.includes(id))
                    : [...new Set([...this.selUnits, ...ids])];
            },

            roleLabel(role) {
                return ROLE[role] || role;
            },

            statusBadge(status) {
                return STATUS[status] || { text: status, color: 'default' };
            },

            // "1 blueprint · 2 views · 4 fælles filer" for a unit tile.
            describe(unit) {
                const counts = {};
                (unit.own || []).forEach(f => { counts[f.role] = (counts[f.role] || 0) + 1; });
                const parts = Object.entries(counts)
                    .filter(([role]) => role !== 'config')
                    .map(([role, n]) => n + ' ' + this.roleLabel(role).toLowerCase() + (n > 1 && !/s$/.test(role) ? (role === 'view' ? 's' : 'er') : ''));
                if ((unit.shared || []).length) parts.push(plural(unit.shared.length, 'fælles fil', 'fælles filer'));
                return parts.join(' · ');
            },

            // For the review: how a unit stands against this site, from its own files.
            unitState(unit) {
                const own = unit.files.filter(f => !f.shared);
                if (own.length && own.every(f => f.status === 'same')) return { text: 'Findes allerede', color: 'default' };
                if (own.length && own.every(f => f.status === 'new')) return { text: 'Ny på dette site', color: 'green' };
                return { text: 'Afviger fra dette site', color: 'amber' };
            },

            sectionState(s) {
                if (!s.registered) return { text: 'Ny på dette site', color: 'green' };
                return s.registry_same ? { text: 'Findes allerede', color: 'default' } : { text: 'Findes, anden opsætning', color: 'amber' };
            },

            async doExport() {
                if (this.total === 0) return;
                this.exporting = true;
                this.exportMsg = '';
                try {
                    const res = await fetch(base() + '/export', this.post({ sections: this.selected, units: this.selUnits }));
                    if (!res.ok) {
                        const data = await res.json().catch(() => ({}));
                        throw new Error(data.error || ('Fejl ' + res.status));
                    }
                    const name = /filename="?([^";]+)"?/.exec(res.headers.get('Content-Disposition') || '');
                    const url = URL.createObjectURL(await res.blob());
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = name ? name[1] : 'pakke.zip';
                    a.click();
                    URL.revokeObjectURL(url);
                    this.exportMsg = 'Pakken er hentet: ' + this.summary + '.';
                    this.exportOk = true;
                } catch (e) {
                    this.exportMsg = e.message;
                    this.exportOk = false;
                } finally {
                    this.exporting = false;
                }
            },

            onFile(e) {
                this.importFile = e.target.files[0] || null;
                this.importMsg = '';
            },

            reset() {
                this.importStep = 'select';
                this.review = null;
                this.writeFiles = {};
                this.registerSections = {};
                this.importMsg = '';
            },

            zipForm() {
                const form = new FormData();
                form.append('zip', this.importFile);
                form.append('_token', this.token);
                return form;
            },

            async doInspect() {
                if (!this.importFile) return;
                this.importing = true;
                this.importMsg = '';
                try {
                    const review = await this.json('/import/inspect', { method: 'POST', body: this.zipForm() });
                    const writeFiles = {};
                    const registerSections = {};
                    review.sections.forEach(s => {
                        registerSections[s.handle] = !s.registered || !s.registry_same;
                        s.files.forEach(f => { writeFiles[f.path] = writeFiles[f.path] || f.suggested; });
                    });
                    (review.units || []).forEach(u => u.files.forEach(f => { writeFiles[f.path] = writeFiles[f.path] || f.suggested; }));
                    this.review = review;
                    this.writeFiles = writeFiles;
                    this.registerSections = registerSections;
                    this.importStep = 'review';
                } catch (e) {
                    this.importMsg = e.message;
                    this.importOk = false;
                } finally {
                    this.importing = false;
                }
            },

            async doImport() {
                this.importing = true;
                this.importMsg = '';
                const form = this.zipForm();
                form.append('choices', JSON.stringify({
                    files: this.writeFiles,
                    sections: Object.keys(this.registerSections).filter(h => this.registerSections[h]),
                }));
                try {
                    const result = await this.json('/import', { method: 'POST', body: form });
                    this.importMsg = result.message;
                    this.importOk = result.rejected.length === 0;
                    this.importStep = 'done';
                } catch (e) {
                    this.importMsg = e.message;
                    this.importOk = false;
                } finally {
                    this.importing = false;
                }
            },
        },

        template: `
<div class="ce">
    <ui-alert v-if="loading" text="Indlæser…" />
    <ui-alert v-else-if="error" variant="error" :text="error" />

    <template v-else>
        <ui-card-panel heading="Eksportér"
            subheading="Vælg hvad der skal med. Alt det bygger på følger med i pakken, og ved import ser du hver fil før noget skrives.">

            <section class="ce-area">
                <div class="ce-area-head">
                    <ui-heading text="Sektioner" size="lg" />
                    <ui-text class="ce-hint" size="sm" variant="subtle" text="Sektionstyper som i registret. Fieldset, blokke, partials, bagt Tailwind og preview følger med." />
                </div>
                <ui-description v-if="!groups.length" text="Der er ingen sektionstyper i registret på dette site." />
                <div v-for="g in groups" :key="g.key" class="ce-box">
                    <div class="ce-box-head">
                        <ui-subheading :text="g.display" size="sm" />
                        <ui-button size="xs" :text="groupSelected(g) ? 'Fravælg alle' : 'Vælg alle'" @click="toggleGroup(g)" />
                    </div>
                    <div class="ce-tiles">
                        <div v-for="s in g.sections" :key="s.handle" class="ce-tile" :class="{ 'is-on': isSelected(s.handle) }" @click="toggleSection(s.handle)">
                            <ui-checkbox solo :model-value="isSelected(s.handle)" :aria-label="s.display" />
                            <div class="ce-tile-body">
                                <div class="ce-tile-title">{{ s.display }}</div>
                                <div class="ce-tile-meta"><span class="ce-mono">{{ s.handle }}</span> · {{ s.files }} filer</div>
                                <div v-if="s.static || s.hidden || s.missing.length" class="ce-tags">
                                    <ui-badge v-if="s.static" text="Statisk" size="sm" />
                                    <ui-badge v-if="s.hidden" text="Skjult for redaktører" size="sm" />
                                    <ui-badge v-if="s.missing.length" text="Mangler referencer" size="sm" color="amber" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section v-for="k in kinds" :key="k.key" class="ce-area">
                <div class="ce-area-head">
                    <ui-heading :text="k.title" size="lg" />
                    <ui-text class="ce-hint" size="sm" variant="subtle" :text="k.hint" />
                    <ui-button v-if="units[k.key].length" size="xs" :text="kindSelected(k) ? 'Fravælg alle' : 'Vælg alle'" @click="toggleKind(k)" />
                </div>
                <div class="ce-box">
                    <div v-if="!units[k.key].length" class="ce-empty">Ingen på dette site.</div>
                    <div v-else class="ce-tiles ce-tiles-wide">
                        <div v-for="u in units[k.key]" :key="u.id" class="ce-tile" :class="{ 'is-on': isUnit(u.id) }" @click="toggleUnit(u.id)">
                            <ui-checkbox solo :model-value="isUnit(u.id)" :aria-label="u.display" />
                            <div class="ce-tile-body">
                                <div class="ce-tile-title">{{ u.display }}</div>
                                <div class="ce-tile-meta"><span class="ce-mono">{{ u.handle }}</span><template v-if="describe(u)"> · {{ describe(u) }}</template></div>
                                <div v-if="u.missing.length" class="ce-tags"><ui-badge text="Mangler referencer" size="sm" color="amber" /></div>
                                <details class="ce-files" @click.stop>
                                    <summary>Vis filer ({{ u.files }})</summary>
                                    <div class="ce-rows">
                                        <div v-for="f in u.own" :key="f.path" class="ce-row ce-row-2">
                                            <ui-badge :text="roleLabel(f.role)" size="sm" />
                                            <span class="ce-mono">{{ f.path }}</span>
                                        </div>
                                        <div v-for="f in u.shared" :key="f.path" class="ce-row ce-row-2">
                                            <ui-badge :text="roleLabel(f.role) + ' · fælles'" size="sm" />
                                            <span class="ce-mono">{{ f.path }}</span>
                                        </div>
                                    </div>
                                </details>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <div v-if="deps.length" class="ce-box" style="margin-top:1.5rem">
                <details class="ce-files">
                    <summary style="margin-top:0;font-size:.875rem;opacity:1">Fælles filer der følger med ({{ deps.length }}) — blokke, partials og indstillinger som flere ting deler. Ved import vælger du for hver enkelt om den skal overskrives.</summary>
                    <div class="ce-rows">
                        <div v-for="d in deps" :key="d.path" class="ce-row ce-row-3">
                            <ui-badge :text="roleLabel(d.role)" size="sm" />
                            <span class="ce-mono">{{ d.label }}</span>
                            <ui-text size="xs" variant="subtle" :text="'bruges af ' + d.usedBy.join(', ')" />
                        </div>
                    </div>
                </details>
            </div>

            <ui-alert v-if="missing.length" variant="warning" heading="Referencer der ikke findes på dette site" style="margin-top:1rem">
                <ul class="ce-list"><li v-for="m in missing" :key="m" class="ce-mono">{{ m }}</li></ul>
            </ui-alert>

            <div class="ce-bar">
                <ui-text size="sm" :variant="total ? 'default' : 'subtle'" :text="total ? 'Valgt: ' + summary : 'Intet valgt endnu.'" />
                <div class="ce-actions">
                    <ui-text v-if="exportMsg" size="sm" :variant="exportOk ? 'success' : 'danger'" :text="exportMsg" />
                    <ui-button variant="primary" icon="download"
                        :text="exporting ? 'Pakker…' : 'Eksportér' + (total ? ' (' + total + ')' : '')"
                        :disabled="exporting || total === 0" @click="doExport" />
                </div>
            </div>
        </ui-card-panel>

        <ui-card-panel heading="Importér en pakke"
            subheading="Upload en ZIP fra et andet site. Du får en gennemgang først: hvad er nyt, hvad har du allerede, og hvad er ændret.">

            <div v-if="importStep === 'select'" class="ce-actions">
                <input type="file" accept=".zip" class="ce-file" @change="onFile">
                <ui-button icon="upload" :text="importing ? 'Læser…' : 'Gennemgå pakken'"
                    :disabled="importing || !importFile" @click="doInspect" />
            </div>

            <template v-if="importStep === 'review' && review">
                <ui-alert v-if="review.legacy" variant="warning"
                    text="Pakken har ingen manifest (lavet med en ældre udgave). Filerne vises enkeltvis, og ingen sektioner registreres." />
                <ui-text v-else size="sm" variant="subtle"
                    :text="'Fra ' + (review.source.name || review.source.url || 'ukendt site') + (review.exported_at ? ', ' + review.exported_at.slice(0, 10) : '') + ' · ' + review.files + ' filer'" />

                <div v-for="s in review.sections" :key="s.handle" class="ce-box" style="margin-top:1rem">
                    <div class="ce-box-head">
                        <div class="ce-title">
                            <ui-heading :text="s.display" />
                            <span class="ce-mono">{{ s.handle }}</span>
                            <ui-badge text="Sektion" size="sm" />
                        </div>
                        <ui-badge size="sm" :text="sectionState(s).text" :color="sectionState(s).color" />
                    </div>
                    <ui-checkbox :model-value="!!registerSections[s.handle]" @update:model-value="registerSections[s.handle] = $event"
                        label="Registrér i sektionslisten" :description="s.group_display ? 'Gruppe: ' + s.group_display : null" />
                    <div class="ce-rows" style="margin-top:.5rem">
                        <div v-for="f in s.files" :key="f.path" class="ce-row">
                            <ui-checkbox solo :model-value="!!writeFiles[f.path]" :disabled="f.status === 'same'"
                                @update:model-value="writeFiles[f.path] = $event" />
                            <div class="ce-cell">
                                <span class="ce-mono">{{ f.path }}</span>
                                <ui-text v-if="f.shared && f.used_by.length > 1" size="xs" variant="subtle"
                                    :text="'også i ' + f.used_by.filter(h => h !== s.handle).join(', ')" />
                            </div>
                            <ui-badge :text="roleLabel(f.role) + (f.shared ? ' · fælles' : '')" size="sm" />
                            <ui-badge :text="statusBadge(f.status).text" :color="statusBadge(f.status).color" size="sm" />
                        </div>
                    </div>
                    <ui-alert v-if="s.missing.length" variant="warning" style="margin-top:.75rem"
                        :text="'Refererer til noget der ikke fandtes på afsender-sitet: ' + s.missing.join(', ')" />
                </div>

                <div v-for="u in review.units" :key="u.id" class="ce-box" style="margin-top:1rem">
                    <div class="ce-box-head">
                        <div class="ce-title">
                            <ui-heading :text="u.display" />
                            <span class="ce-mono">{{ u.handle }}</span>
                            <ui-badge :text="roleLabel(u.kind)" size="sm" />
                        </div>
                        <ui-badge size="sm" :text="unitState(u).text" :color="unitState(u).color" />
                    </div>
                    <div class="ce-rows">
                        <div v-for="f in u.files" :key="f.path" class="ce-row">
                            <ui-checkbox solo :model-value="!!writeFiles[f.path]" :disabled="f.status === 'same'"
                                @update:model-value="writeFiles[f.path] = $event" />
                            <div class="ce-cell">
                                <span class="ce-mono">{{ f.path }}</span>
                                <ui-text v-if="f.shared && f.used_by.length > 1" size="xs" variant="subtle"
                                    :text="'også i ' + f.used_by.filter(h => h !== u.display).join(', ')" />
                            </div>
                            <ui-badge :text="roleLabel(f.role) + (f.shared ? ' · fælles' : '')" size="sm" />
                            <ui-badge :text="statusBadge(f.status).text" :color="statusBadge(f.status).color" size="sm" />
                        </div>
                    </div>
                    <ui-alert v-if="u.missing.length" variant="warning" style="margin-top:.75rem"
                        :text="'Refererer til noget der ikke fandtes på afsender-sitet: ' + u.missing.join(', ')" />
                </div>

                <div class="ce-bar">
                    <ui-text size="sm" variant="subtle" :text="reviewWriteCount + ' fil(er) skrives' + (reviewRegisterCount ? ', ' + reviewRegisterCount + ' sektion(er) registreres' : '')" />
                    <div class="ce-actions">
                        <ui-button variant="ghost" text="Tilbage" @click="reset" />
                        <ui-button variant="primary" icon="upload"
                            :text="importing ? 'Importerer…' : 'Importér'"
                            :disabled="importing || (reviewWriteCount === 0 && reviewRegisterCount === 0)" @click="doImport" />
                    </div>
                </div>
            </template>

            <template v-if="importStep === 'done'">
                <ui-alert :variant="importOk ? 'success' : 'warning'" :text="importMsg" />
                <div class="ce-actions" style="margin-top:.75rem">
                    <ui-button text="Importér en anden pakke" @click="reset" />
                </div>
            </template>

            <ui-alert v-if="importMsg && importStep !== 'done'" variant="error" :text="importMsg" style="margin-top:.75rem" />
        </ui-card-panel>
    </template>
</div>
        `,
    };

    Statamic.booting(() => {
        Statamic.component('component-exporter-utility', Utility);
    });
}());
