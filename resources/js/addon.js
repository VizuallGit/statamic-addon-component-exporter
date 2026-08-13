/**
 * Komponent Eksport — CP-utility.
 *
 * Flyttet fra projektets resources/js/cp.js, hvor den lå sammen med alt muligt
 * andet. Almindelig JS, ingen .vue-fil og intet byggetrin: filen udgives som
 * den er til public/vendor/component-exporter/js/ og indlæses af Statamic.
 *
 * Ruterne slås op relativt til utility'ens egen URL (window.location.pathname),
 * så addonet ikke behøver vide hvad CP'et hedder eller hvor det er monteret.
 */
(function () {
    'use strict';

    const ComponentExporterUtility = {
        name: 'ComponentExporterUtility',

        props: {
            token: { type: String, default: '' },
        },

        data() {
            return {
                loading: true,
                fetchError: false,
                sections: [],
                blueprints: [],
                collections: [],
                selSections: [],
                selBlueprints: [],
                selCollections: [],
                exporting: false,
                exportMsg: '',
                exportOk: true,
                importing: false,
                importMsg: '',
                importOk: true,
                importFile: null,
                importStep: 'select',   // 'select' | 'review' | 'done'
                conflicts: [],          // paths med konflikt
                resolutions: {},        // path → 'overwrite'|'keep'|'copy'
            };
        },

        computed: {
            deps() {
                const map = new Map();
                this.sections
                    .filter(s => this.selSections.includes(s.handle))
                    .forEach(s => {
                        (s.deps || []).forEach(dep => {
                            if (!map.has(dep)) map.set(dep, []);
                            map.get(dep).push(s.title);
                        });
                    });
                return [...map.entries()].map(([handle, usedBy]) => ({ handle, usedBy }));
            },
            groupedBlueprints() {
                const groups = {};
                this.blueprints.forEach(b => {
                    if (!groups[b.category]) groups[b.category] = [];
                    groups[b.category].push(b);
                });
                return Object.entries(groups).map(([cat, items]) => ({ cat, items }));
            },
            totalSelected() {
                return this.selSections.length + this.selBlueprints.length + this.selCollections.length;
            },
            allSectionsSelected() {
                return this.sections.length > 0 && this.selSections.length === this.sections.length;
            },
            allBlueprintsSelected() {
                return this.blueprints.length > 0 && this.selBlueprints.length === this.blueprints.length;
            },
            allCollectionsSelected() {
                return this.collections.length > 0 && this.selCollections.length === this.collections.length;
            },
        },

        mounted() {
            // Inject styles
            if (!document.getElementById('ce-styles')) {
                const s = document.createElement('style');
                s.id = 'ce-styles';
                s.textContent = '.ce-item{display:flex;align-items:center;gap:.5rem;padding:.4rem .6rem;border-radius:.375rem;border:1px solid transparent;cursor:pointer;font-size:.875rem}.ce-item:hover{background:#f9fafb;border-color:#e5e7eb}.ce-item input[type=checkbox]{flex-shrink:0;cursor:pointer}';
                document.head.appendChild(s);
            }

            const base = window.location.pathname.replace(/\/$/, '');
            fetch(base + '/items')
                .then(r => r.json())
                .then(data => {
                    this.sections    = data.page_sections || [];
                    this.blueprints  = data.blueprints    || [];
                    this.collections = data.collections   || [];
                    this.selSections = data.selection?.page_sections ?? [];
                    this.loading     = false;
                })
                .catch(() => {
                    this.fetchError = true;
                    this.loading    = false;
                });
        },

        methods: {
            toggle(arr, val) {
                const i = arr.indexOf(val);
                i === -1 ? arr.push(val) : arr.splice(i, 1);
            },
            selectAll(arr, items, key, checked) {
                arr.splice(0);
                if (checked) arr.push(...items.map(i => i[key]));
            },

            async toggleSection(handle) {
                this.toggle(this.selSections, handle);
                const base = window.location.pathname.replace(/\/$/, '');
                await fetch(base + '/selection/toggle', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                    body:    JSON.stringify({ handle }),
                });
            },

            async selectAllSections(checked) {
                this.selectAll(this.selSections, this.sections, 'handle', checked);
                const base = window.location.pathname.replace(/\/$/, '');
                await fetch(base + '/selection/toggle', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                    body:    JSON.stringify({ set: this.selSections }),
                });
            },
            onFileChange(e) {
                this.importFile = e.target.files[0] ?? null;
                this.importStep = 'select';
                this.conflicts  = [];
                this.resolutions = {};
                this.importMsg  = '';
            },

            shortPath(path) {
                return path.replace(/^resources\//, '');
            },

            async doExport() {
                if (this.totalSelected === 0) return;
                this.exporting = true;
                this.exportMsg = '';
                const base = window.location.pathname.replace(/\/$/, '');
                try {
                    const res = await fetch(base + '/export', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        body: JSON.stringify({
                            selected: {
                                page_sections: this.selSections,
                                blueprints:    this.selBlueprints,
                                collections:   this.selCollections,
                            },
                        }),
                    });
                    if (!res.ok) throw new Error();
                    const blob = await res.blob();
                    const url  = URL.createObjectURL(blob);
                    const a    = document.createElement('a');
                    a.href     = url;
                    a.download = 'components-export.zip';
                    a.click();
                    URL.revokeObjectURL(url);
                    this.exportMsg = `✓ Eksporteret (${this.totalSelected} valgt)`;
                    this.exportOk  = true;
                } catch {
                    this.exportMsg = 'Fejl under eksport. Prøv igen.';
                    this.exportOk  = false;
                } finally {
                    this.exporting = false;
                }
            },

            async doCheck() {
                if (!this.importFile) {
                    this.importMsg = 'Vælg en ZIP-fil først.';
                    this.importOk  = false;
                    return;
                }
                this.importing = true;
                this.importMsg = '';
                const base = window.location.pathname.replace(/\/$/, '');
                const form = new FormData();
                form.append('zip', this.importFile);
                form.append('_token', this.csrf());
                try {
                    const res  = await fetch(base + '/import/check', { method: 'POST', body: form });
                    const data = await res.json();
                    if (!res.ok) throw new Error(data.error ?? 'Tjek fejlede.');
                    this.conflicts = data.conflicts ?? [];
                    // Sæt standard-valg: overwrite for alt
                    this.resolutions = {};
                    this.conflicts.forEach(p => { this.resolutions[p] = 'overwrite'; });
                    this.importStep = 'review';
                } catch (e) {
                    this.importMsg = e.message;
                    this.importOk  = false;
                } finally {
                    this.importing = false;
                }
            },

            async doImport() {
                this.importing = true;
                this.importMsg = 'Importerer…';
                const base = window.location.pathname.replace(/\/$/, '');
                const form = new FormData();
                form.append('zip', this.importFile);
                form.append('_token', this.csrf());
                form.append('resolutions', JSON.stringify(this.resolutions));
                try {
                    const res  = await fetch(base + '/import', { method: 'POST', body: form });
                    const data = await res.json();
                    this.importMsg = res.ok ? '✓ ' + data.message : (data.error ?? 'Import fejlede.');
                    this.importOk  = res.ok;
                    if (res.ok) this.importStep = 'done';
                } catch {
                    this.importMsg = 'Fejl under import. Prøv igen.';
                    this.importOk  = false;
                } finally {
                    this.importing = false;
                }
            },

            csrf() {
                return this.token;
            },
        },

        template: `
            <div style="max-width:900px">
                <div v-if="loading" class="card p-6 text-center" style="color:#6b7280">Indlæser komponenter…</div>
                <div v-else-if="fetchError" class="card p-6" style="color:#dc2626">Kunne ikke indlæse komponenter.</div>
                <template v-else>

                    <div class="card p-6 mb-4">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
                            <h2 style="font-size:1.1rem;font-weight:700;margin:0">Page Sections</h2>
                            <label style="font-size:.85rem;cursor:pointer;display:flex;align-items:center;gap:.4rem">
                                <input type="checkbox" :checked="allSectionsSelected" @change="selectAllSections($event.target.checked)">
                                Vælg alle
                            </label>
                        </div>
                        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:.4rem">
                            <label v-for="s in sections" :key="s.handle" class="ce-item">
                                <input type="checkbox" :checked="selSections.includes(s.handle)" @change="toggleSection(s.handle)">
                                <span>{{ s.title }}</span>
                            </label>
                        </div>
                    </div>

                    <div v-if="deps.length" class="card p-6 mb-4" style="border-left:3px solid #3b82f6">
                        <h3 style="font-size:1rem;font-weight:700;margin:0 0 .4rem">Automatisk inkluderede afhængigheder</h3>
                        <p style="font-size:.8rem;color:#6b7280;margin:0 0 .75rem">Disse fieldsets kræves af de valgte sections og medtages automatisk.</p>
                        <div v-for="d in deps" :key="d.handle" style="display:flex;align-items:center;gap:.5rem;padding:.25rem 0;font-size:.82rem">
                            <span style="width:.5rem;height:.5rem;border-radius:50%;background:#22c55e;flex-shrink:0;display:inline-block"></span>
                            <code style="background:#f3f4f6;padding:.1rem .4rem;border-radius:.25rem;font-size:.78rem">{{ d.handle }}.yaml</code>
                            <span style="color:#6b7280">krævet af: {{ d.usedBy.join(', ') }}</span>
                        </div>
                    </div>

                    <div class="card p-6 mb-4">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
                            <h2 style="font-size:1.1rem;font-weight:700;margin:0">Blueprints</h2>
                            <label style="font-size:.85rem;cursor:pointer;display:flex;align-items:center;gap:.4rem">
                                <input type="checkbox" :checked="allBlueprintsSelected" @change="selectAll(selBlueprints, blueprints, 'path', $event.target.checked)">
                                Vælg alle
                            </label>
                        </div>
                        <div v-for="group in groupedBlueprints" :key="group.cat">
                            <p style="font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:#9ca3af;margin:.75rem 0 .35rem">{{ group.cat }}</p>
                            <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:.4rem">
                                <label v-for="b in group.items" :key="b.path" class="ce-item">
                                    <input type="checkbox" :checked="selBlueprints.includes(b.path)" @change="toggle(selBlueprints, b.path)">
                                    <span>{{ b.title }}</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="card p-6 mb-4">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
                            <h2 style="font-size:1.1rem;font-weight:700;margin:0">
                                Collections
                                <span style="font-size:.8rem;font-weight:400;color:#6b7280">(kun konfiguration)</span>
                            </h2>
                            <label style="font-size:.85rem;cursor:pointer;display:flex;align-items:center;gap:.4rem">
                                <input type="checkbox" :checked="allCollectionsSelected" @change="selectAll(selCollections, collections, 'handle', $event.target.checked)">
                                Vælg alle
                            </label>
                        </div>
                        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:.4rem">
                            <label v-for="c in collections" :key="c.handle" class="ce-item">
                                <input type="checkbox" :checked="selCollections.includes(c.handle)" @change="toggle(selCollections, c.handle)">
                                <span>{{ c.title }}</span>
                            </label>
                        </div>
                    </div>

                    <div class="card p-6 mb-4">
                        <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
                            <button class="btn-primary btn" :disabled="exporting || totalSelected === 0" @click="doExport">
                                {{ exporting ? 'Eksporterer…' : 'Eksporter som ZIP' }}
                            </button>
                            <span v-if="exportMsg" :style="{ color: exportOk ? '#16a34a' : '#dc2626', fontSize: '.85rem' }">{{ exportMsg }}</span>
                        </div>
                    </div>

                    <div class="card p-6">
                        <h2 style="font-size:1.1rem;font-weight:700;margin:0 0 .4rem">Importer</h2>
                        <p style="font-size:.85rem;color:#6b7280;margin:0 0 1rem">Upload en ZIP-fil eksporteret fra et andet site.</p>

                        <div v-if="importStep === 'select'" style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
                            <input type="file" accept=".zip" style="font-size:.85rem" @change="onFileChange">
                            <button class="btn-default btn" :disabled="importing || !importFile" @click="doCheck">
                                {{ importing ? 'Analyserer…' : 'Analysér ZIP' }}
                            </button>
                        </div>

                        <div v-if="importStep === 'review'">
                            <div v-if="conflicts.length === 0" style="font-size:.85rem;color:#16a34a;margin-bottom:1rem">
                                ✓ Ingen konflikter — alle filer er nye.
                            </div>
                            <div v-else style="margin-bottom:1rem">
                                <p style="font-size:.85rem;font-weight:600;margin:0 0 .5rem">{{ conflicts.length }} fil(er) eksisterer allerede — vælg hvad der skal ske:</p>
                                <div style="border:1px solid #e5e7eb;border-radius:.5rem;overflow:hidden">
                                    <div v-for="(path, idx) in conflicts" :key="path"
                                         :style="{ background: idx % 2 === 0 ? '#f9fafb' : '#fff', padding:'.5rem .75rem', display:'flex', alignItems:'center', gap:'.75rem', flexWrap:'wrap' }">
                                        <code style="flex:1;font-size:.78rem;color:#374151">{{ shortPath(path) }}</code>
                                        <div style="display:flex;gap:.4rem">
                                            <label v-for="opt in [{v:'keep',l:'Behold'},{v:'overwrite',l:'Overskriv'},{v:'copy',l:'Gem kopi'}]"
                                                   :key="opt.v"
                                                   :style="{ display:'flex', alignItems:'center', gap:'.25rem', fontSize:'.8rem', cursor:'pointer',
                                                             padding:'.2rem .5rem', borderRadius:'.3rem', border:'1px solid',
                                                             borderColor: resolutions[path]===opt.v ? '#3b82f6' : '#d1d5db',
                                                             background: resolutions[path]===opt.v ? '#eff6ff' : 'transparent',
                                                             color: resolutions[path]===opt.v ? '#1d4ed8' : '#374151' }">
                                                <input type="radio" :name="'res_'+idx" :value="opt.v" v-model="resolutions[path]" style="display:none">
                                                {{ opt.l }}
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div style="display:flex;align-items:center;gap:.75rem">
                                <button class="btn-primary btn" :disabled="importing" @click="doImport">
                                    {{ importing ? 'Importerer…' : 'Gennemfør import' }}
                                </button>
                                <button class="btn-default btn" @click="importStep = 'select'">Tilbage</button>
                            </div>
                        </div>

                        <div v-if="importMsg" style="margin-top:.75rem;font-size:.85rem" :style="{ color: importOk ? '#16a34a' : '#dc2626' }">{{ importMsg }}</div>
                    </div>

                </template>
            </div>
        `,
    };

    Statamic.booting(() => {
        Statamic.component('component-exporter-utility', ComponentExporterUtility);
    });
}());
