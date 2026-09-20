/**
 * Komponent Eksport — the Control Panel utility.
 *
 * Plain JS, no build step: the file is published as it is to
 * public/vendor/component-exporter/js/ and loaded by Statamic. It is one Vue
 * component built from Statamic's own UI kit (window.__STATAMIC__.ui), so it
 * follows the Control Panel's light and dark themes with no colours of its own.
 *
 * Routes are resolved relative to the utility's own URL, so the addon need not
 * know what the Control Panel is called or where it is mounted.
 *
 * Two panels, one thing at a time: export (pick section types, see what
 * travels with them, download) and import (upload, review file by file, apply).
 */
(function () {
    'use strict';

    const ui = (window.__STATAMIC__ && window.__STATAMIC__.ui) || {};

    const ROLE = {
        fieldset: 'Fieldset',
        partial: 'Partial',
        css: 'Tailwind',
        preview: 'Preview',
        blueprint: 'Blueprint',
        collection: 'Collection',
        file: 'Fil',
    };

    const STATUS = {
        new: { text: 'Ny', color: 'green' },
        same: { text: 'Uændret', color: 'default' },
        changed: { text: 'Ændret', color: 'amber' },
    };

    const STYLE = `
.ce{display:grid;gap:1.5rem;max-width:64rem}
.ce-group+.ce-group{margin-top:1.25rem}
.ce-head{display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-bottom:.5rem}
.ce-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(17rem,1fr));gap:.5rem .75rem}
.ce-item{display:flex;flex-direction:column;gap:.25rem}
.ce-tags{display:flex;gap:.25rem;flex-wrap:wrap;padding-inline-start:1.75rem}
.ce-block{margin-top:1.5rem}
.ce-row{display:grid;grid-template-columns:auto minmax(0,1fr) auto auto;gap:.75rem;align-items:center;padding:.4rem 0;border-top:1px solid color-mix(in srgb,currentColor 12%,transparent)}
.ce-row.ce-row-3{grid-template-columns:auto minmax(0,1fr) auto}
.ce-rows>.ce-row:first-child{border-top:0}
.ce-cell{min-width:0;display:flex;flex-direction:column;gap:.15rem}
.ce-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.75rem;overflow-wrap:anywhere}
.ce-actions{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-top:1.5rem}
.ce-title{display:flex;align-items:baseline;gap:.5rem;flex-wrap:wrap}
.ce-extras summary{cursor:pointer;font-size:.875rem}
.ce-extras>.ce-grid,.ce-extras>.ce-group{margin-top:.75rem}
.ce-list{margin:0;padding-inline-start:1rem}
.ce-file{font-size:.875rem}
`;

    const base = () => window.location.pathname.replace(/\/$/, '');

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
                loading: true,
                error: '',
                groups: [],
                blueprints: [],
                collections: [],
                selected: [],
                selExtras: [],
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
            selectedSections() {
                return this.sections.filter(s => this.selected.includes(s.handle));
            },
            // Shared files across the selected sections, each with the sections that use it.
            deps() {
                const map = new Map();
                this.selectedSections.forEach(s => (s.shared || []).forEach(f => {
                    if (!map.has(f.path)) map.set(f.path, { ...f, usedBy: [] });
                    map.get(f.path).usedBy.push(s.display);
                }));
                return [...map.values()].sort((a, b) => a.role.localeCompare(b.role) || a.label.localeCompare(b.label));
            },
            missing() {
                const out = new Set();
                this.selectedSections.forEach(s => (s.missing || []).forEach(m => out.add(s.display + ' → ' + m)));
                return [...out];
            },
            total() {
                return this.selected.length + this.selExtras.length;
            },
            groupedBlueprints() {
                const groups = {};
                this.blueprints.forEach(b => { (groups[b.category] ||= []).push(b); });
                return Object.entries(groups).map(([category, items]) => ({ category, items }));
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
                    this.blueprints = data.blueprints || [];
                    this.collections = data.collections || [];
                    const known = new Set(this.sections.map(s => s.handle));
                    this.selected = (data.selection?.page_sections || []).filter(h => known.has(h));
                } catch (e) {
                    this.error = 'Kunne ikke indlæse sektionerne: ' + e.message;
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

            async toggleGroup(group, on) {
                const handles = group.sections.map(s => s.handle);
                this.selected = on
                    ? [...new Set([...this.selected, ...handles])]
                    : this.selected.filter(h => !handles.includes(h));
                await this.json('/selection/toggle', this.post({ set: this.selected })).catch(() => {});
            },

            toggleExtra(path) {
                const i = this.selExtras.indexOf(path);
                i === -1 ? this.selExtras.push(path) : this.selExtras.splice(i, 1);
            },

            roleLabel(role) {
                return ROLE[role] || role;
            },

            statusBadge(status) {
                return STATUS[status] || { text: status, color: 'default' };
            },

            async doExport() {
                if (this.total === 0) return;
                this.exporting = true;
                this.exportMsg = '';
                try {
                    const res = await fetch(base() + '/export', this.post({ sections: this.selected, extras: this.selExtras }));
                    if (!res.ok) {
                        const data = await res.json().catch(() => ({}));
                        throw new Error(data.error || ('Fejl ' + res.status));
                    }
                    const name = /filename="?([^";]+)"?/.exec(res.headers.get('Content-Disposition') || '');
                    const url = URL.createObjectURL(await res.blob());
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = name ? name[1] : 'sektioner.zip';
                    a.click();
                    URL.revokeObjectURL(url);
                    this.exportMsg = 'Pakken er hentet (' + this.total + ' valgt).';
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
                    review.extras.forEach(f => { writeFiles[f.path] = f.suggested; });
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
    <ui-alert v-if="loading" text="Indlæser sektionerne…" />
    <ui-alert v-else-if="error" variant="error" :text="error" />

    <template v-else>
        <ui-card-panel heading="Eksportér sektioner"
            subheading="Vælg de sektionstyper der skal med. Alt de bygger på — fieldsets, blokke, partials, bagt Tailwind og preview — følger med i pakken.">

            <ui-description v-if="!sections.length" text="Der er ingen sektionstyper i registret på dette site." />

            <div v-for="g in groups" :key="g.key" class="ce-group">
                <div class="ce-head">
                    <ui-subheading :text="g.display" size="sm" />
                    <ui-button size="xs" variant="ghost"
                        :text="groupSelected(g) ? 'Fravælg alle' : 'Vælg alle'"
                        @click="toggleGroup(g, !groupSelected(g))" />
                </div>
                <div class="ce-grid">
                    <div v-for="s in g.sections" :key="s.handle" class="ce-item">
                        <ui-checkbox :model-value="isSelected(s.handle)" @update:model-value="toggleSection(s.handle)"
                            :label="s.display" :description="s.handle + ' · ' + s.files + ' filer'" />
                        <div v-if="s.static || s.hidden || s.missing.length" class="ce-tags">
                            <ui-badge v-if="s.static" text="Statisk" size="sm" />
                            <ui-badge v-if="s.hidden" text="Skjult for redaktører" size="sm" />
                            <ui-badge v-if="s.missing.length" text="Mangler referencer" size="sm" color="amber" />
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="deps.length" class="ce-block">
                <ui-subheading text="Fælles filer der følger med" size="sm" />
                <ui-description text="Blokke, partials og indstillinger som flere sektioner deler. Ved import vælger du for hver enkelt om den skal overskrives." />
                <div class="ce-rows">
                    <div v-for="d in deps" :key="d.path" class="ce-row ce-row-3">
                        <ui-badge :text="roleLabel(d.role)" size="sm" />
                        <span class="ce-mono">{{ d.label }}</span>
                        <ui-text size="xs" variant="subtle" :text="'bruges af ' + d.usedBy.join(', ')" />
                    </div>
                </div>
            </div>

            <ui-alert v-if="missing.length" variant="warning" heading="Referencer der ikke findes på dette site" class="ce-block">
                <ul class="ce-list"><li v-for="m in missing" :key="m" class="ce-mono">{{ m }}</li></ul>
            </ui-alert>

            <details class="ce-extras ce-block">
                <summary>Blueprints og collections (kun konfiguration)</summary>
                <div v-for="group in groupedBlueprints" :key="group.category" class="ce-group">
                    <ui-subheading :text="group.category" size="sm" />
                    <div class="ce-grid">
                        <ui-checkbox v-for="b in group.items" :key="b.path"
                            :model-value="selExtras.includes(b.path)" @update:model-value="toggleExtra(b.path)"
                            :label="b.title" :description="b.path" />
                    </div>
                </div>
                <div v-if="collections.length" class="ce-group">
                    <ui-subheading text="Collections" size="sm" />
                    <div class="ce-grid">
                        <ui-checkbox v-for="c in collections" :key="c.path"
                            :model-value="selExtras.includes(c.path)" @update:model-value="toggleExtra(c.path)"
                            :label="c.title" :description="c.path" />
                    </div>
                </div>
            </details>

            <div class="ce-actions">
                <ui-button variant="primary" icon="download"
                    :text="exporting ? 'Pakker…' : 'Eksportér' + (total ? ' (' + total + ')' : '')"
                    :disabled="exporting || total === 0" @click="doExport" />
                <ui-text v-if="exportMsg" size="sm" :variant="exportOk ? 'success' : 'danger'" :text="exportMsg" />
            </div>
        </ui-card-panel>

        <ui-card-panel heading="Importér en pakke"
            subheading="Upload en ZIP fra et andet site. Du får en gennemgang først: hvad er nyt, hvad har du allerede, og hvad er ændret.">

            <div v-if="importStep === 'select'" class="ce-actions" style="margin-top:0">
                <input type="file" accept=".zip" class="ce-file" @change="onFile">
                <ui-button icon="upload" :text="importing ? 'Læser…' : 'Gennemgå pakken'"
                    :disabled="importing || !importFile" @click="doInspect" />
            </div>

            <template v-if="importStep === 'review' && review">
                <ui-alert v-if="review.legacy" variant="warning"
                    text="Pakken har ingen manifest (lavet med en ældre udgave). Filerne vises enkeltvis, og ingen sektioner registreres." />
                <ui-text v-else size="sm" variant="subtle"
                    :text="'Fra ' + (review.source.name || review.source.url || 'ukendt site') + (review.exported_at ? ', ' + review.exported_at.slice(0, 10) : '') + ' · ' + review.files + ' filer'" />

                <div v-for="s in review.sections" :key="s.handle" class="ce-block">
                    <div class="ce-head">
                        <div class="ce-title">
                            <ui-heading :text="s.display" />
                            <span class="ce-mono">{{ s.handle }}</span>
                        </div>
                        <ui-badge size="sm"
                            :text="s.registered ? (s.registry_same ? 'Findes allerede' : 'Findes, anden opsætning') : 'Ny på dette site'"
                            :color="s.registered ? (s.registry_same ? 'default' : 'amber') : 'green'" />
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

                <div v-if="review.extras.length" class="ce-block">
                    <ui-subheading text="Øvrige filer" size="sm" />
                    <div class="ce-rows" style="margin-top:.5rem">
                        <div v-for="f in review.extras" :key="f.path" class="ce-row">
                            <ui-checkbox solo :model-value="!!writeFiles[f.path]" :disabled="f.status === 'same'"
                                @update:model-value="writeFiles[f.path] = $event" />
                            <span class="ce-mono">{{ f.path }}</span>
                            <ui-badge :text="roleLabel(f.role)" size="sm" />
                            <ui-badge :text="statusBadge(f.status).text" :color="statusBadge(f.status).color" size="sm" />
                        </div>
                    </div>
                </div>

                <div class="ce-actions">
                    <ui-button variant="primary"
                        :text="importing ? 'Importerer…' : 'Importér ' + reviewWriteCount + ' fil(er)' + (reviewRegisterCount ? ', registrér ' + reviewRegisterCount : '')"
                        :disabled="importing || (reviewWriteCount === 0 && reviewRegisterCount === 0)" @click="doImport" />
                    <ui-button variant="ghost" text="Tilbage" @click="reset" />
                </div>
            </template>

            <template v-if="importStep === 'done'">
                <ui-alert :variant="importOk ? 'success' : 'warning'" :text="importMsg" />
                <div class="ce-actions">
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
