/**
 * The floor map editor.
 *
 * One Alpine component, loaded on demand by Filament (x-load), holding a
 * floor's map as a draft in the browser. Nothing is written to the server
 * until Save; every change until then is on the undo stack.
 *
 * How it is built, and why:
 *
 *  - Map objects are NOT Alpine-reactive. A floor holds hundreds of them, and
 *    a deep reactive proxy over each would make every drag frame re-run
 *    thousands of bindings. They live in a plain Map in this closure, and the
 *    SVG is written directly: a changed object replaces its own <g>, nothing
 *    else. Alpine only holds the UI around the map — tool, selection, panel.
 *
 *  - Everything is metres. An object is a rotated rectangle: centre (x, y),
 *    width along its own x axis, depth along its own y, height upwards from z,
 *    rotation clockwise in degrees. Two projections draw the same objects: a
 *    plan view (x → right, y → down) and an isometric view, where height is
 *    drawn. Pointer positions are projected back onto the floor plane, so
 *    dragging, resizing and rotating work identically in both.
 *
 *  - Everything that reaches innerHTML is escaped. Labels, desk names and sign
 *    text are typed by people.
 *
 * No network access and no dependencies: this file is served from the
 * application's own server.
 */

const SVG_NS = 'http://www.w3.org/2000/svg'
const COS30 = Math.cos(Math.PI / 6)
const SIN30 = 0.5
const LABEL_MIN_PX_PER_M = 14
const HISTORY_LIMIT = 200
const LOCATE_ZOOM = 3
const LOCATE_FLIGHT_MS = 700
const LOCATE_GLOW_MS = 8000

// ---- small helpers ------------------------------------------------------

const clamp = (value, min, max) => Math.min(max, Math.max(min, value))
const round = (value, places = 3) => Math.round(value * 10 ** places) / 10 ** places
const rad = (deg) => (deg * Math.PI) / 180
const normAngle = (deg) => ((deg % 360) + 360) % 360

const escapeHtml = (value) =>
    String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')

const clone = (object) => ({ ...object, props: { ...(object.props || {}) } })

function shade(hex, factor) {
    const match = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex || '')

    if (!match) return hex

    const channel = (i) => clamp(Math.round(parseInt(match[i], 16) * factor), 0, 255)

    return `rgb(${channel(1)}, ${channel(2)}, ${channel(3)})`
}

const sameObject = (a, b) =>
    a && b && JSON.stringify(a) === JSON.stringify(b)

// ---- the component ------------------------------------------------------

export default function floorMap(config) {
    const types = Object.fromEntries(config.types.map((type) => [type.key, type]))
    const floor = config.floor
    const desks = new Map(Object.entries(config.desks).map(([id, desk]) => [Number(id), desk]))

    /** @type {Map<string, object>} key => object */
    let objects = new Map()
    let nextKey = 1
    let revision = config.revision
    const history = { undo: [], redo: [] }

    let svg = null
    let layers = {}
    let k = 20 // pixels per metre
    let interaction = null
    let frame = null
    let spaceHeld = false
    let flight = null
    let glowTimer = null
    /** The desk lit up by a locate: its object key, until the glow fades. */
    let glowing = null

    const keyFor = (object) => (object.id ? `o${object.id}` : `n${nextKey++}`)

    function load(list) {
        objects = new Map()

        for (const raw of list) {
            const object = normalise(raw)
            object.key = keyFor(object)
            objects.set(object.key, object)
        }
    }

    function normalise(raw) {
        return {
            id: raw.id ?? null,
            type: raw.type,
            workstation_id: raw.workstation_id ?? null,
            label: raw.label ?? null,
            x: Number(raw.x),
            y: Number(raw.y),
            z: Number(raw.z ?? 0),
            width: Number(raw.width),
            depth: Number(raw.depth),
            height: Number(raw.height ?? 0),
            rotation: Number(raw.rotation ?? 0),
            props: { ...(raw.props || {}) },
            locked: Boolean(raw.locked),
        }
    }

    load(config.objects)

    return {
        // ---- UI state (reactive) -----------------------------------------
        canArrange: config.canArrange,
        canCreateDesks: config.canCreateDesks,
        mode: 'view',
        projection: 'top',
        tool: 'select',
        zoom: 1,
        grid: true,
        snap: true,
        step: 0.5,
        labels: true,
        cutaway: true,
        cursor: null,
        selection: [],
        panel: null,
        dirty: false,
        saving: false,
        canUndo: false,
        canRedo: false,
        tray: [],
        trayFilter: '',
        pendingDesk: null,
        search: '',
        searchMiss: false,
        fill: null,
        newDesk: null,
        conflict: false,
        ghost: null,
        marquee: null,
        callout: null,
        canSearch: Boolean(config.canSearch),
        searchUrl: config.searchUrl,
        counts: { objects: 0, placed: 0, desks: desks.size },
        types: config.types,
        floors: config.floors,

        // ---- lifecycle ---------------------------------------------------

        init() {
            svg = this.$refs.svg
            layers = {
                viewport: this.$refs.viewport,
                plane: this.$refs.plane,
                glow: this.$refs.glow,
                objects: this.$refs.objects,
                overlay: this.$refs.overlay,
            }

            this.refreshTray()
            this.renderPlane()
            this.renderAll()
            this.$nextTick(() => {
                this.fit()
                this.locateFromLink()
            })

            new ResizeObserver(() => this.applyView()).observe(svg)

            window.addEventListener('beforeunload', (event) => {
                if (!this.dirty) return
                event.preventDefault()
                event.returnValue = ''
            })

            window.addEventListener('keyup', (event) => {
                if (event.code === 'Space') spaceHeld = false
            })

            this.$wire.on('desk-details-saved', (payload) => {
                const data = Array.isArray(payload) ? payload[0] : payload
                const desk = desks.get(Number(data.workstation))

                if (!desk) return

                Object.assign(desk, data.desk || {}, { details: Boolean(data.hasDetails) })
                this.renderAll()
                this.syncPanel()
            })
        },

        get isEditing() {
            return this.mode === 'edit'
        },

        get filteredTray() {
            const needle = this.trayFilter.trim().toLowerCase()

            return needle ? this.tray.filter((desk) => desk.name.toLowerCase().includes(needle)) : this.tray
        },

        typeLabel(key) {
            return types[key]?.label ?? key
        },

        // ---- modes and tools --------------------------------------------

        setMode(mode) {
            if (mode === 'edit' && !this.canArrange) return

            this.mode = mode
            this.tool = 'select'
            this.pendingDesk = null

            if (mode === 'view') this.clearSelection()

            this.renderOverlay()
        },

        setTool(tool) {
            if (!this.isEditing) return

            this.tool = tool
            this.pendingDesk = null
            this.fill = null
        },

        setProjection(projection) {
            this.projection = projection
            this.renderPlane()
            this.renderAll()
            this.fit()
        },

        // ---- geometry ----------------------------------------------------

        project(x, y, z = 0) {
            if (this.projection === 'iso') {
                return [(x - y) * COS30, (x + y) * SIN30 - z]
            }

            return [x, y]
        },

        /** A screen point onto the floor plane, in metres. */
        toWorld(clientX, clientY) {
            const rect = svg.getBoundingClientRect()
            const u = (clientX - rect.left - this.panX) / k
            const v = (clientY - rect.top - this.panY) / k

            if (this.projection === 'iso') {
                return { x: (u / COS30 + v / SIN30) / 2, y: (v / SIN30 - u / COS30) / 2 }
            }

            return { x: u, y: v }
        },

        localToWorld(object, lx, ly) {
            const r = rad(object.rotation)

            return {
                x: object.x + lx * Math.cos(r) - ly * Math.sin(r),
                y: object.y + lx * Math.sin(r) + ly * Math.cos(r),
            }
        },

        worldToLocal(object, x, y) {
            const r = rad(-object.rotation)
            const dx = x - object.x
            const dy = y - object.y

            return { x: dx * Math.cos(r) - dy * Math.sin(r), y: dx * Math.sin(r) + dy * Math.cos(r) }
        },

        snapValue(value) {
            return this.snap ? round(Math.round(value / this.step) * this.step) : round(value)
        },

        // ---- view: zoom and pan -----------------------------------------

        panX: 0,
        panY: 0,
        baseK: 20,

        toggleLabels() {
            this.labels = !this.labels
            this.applyView()
        },

        /** Walls and doors drawn low, so the isometric view can see over them. */
        toggleCutaway() {
            this.cutaway = !this.cutaway
            this.renderAll()
        },

        fit() {
            const rect = svg.getBoundingClientRect()

            if (!rect.width || !rect.height) return

            const corners = [
                this.project(0, 0),
                this.project(floor.width, 0),
                this.project(floor.width, floor.depth),
                this.project(0, floor.depth),
            ]
            const us = corners.map((c) => c[0])
            const vs = corners.map((c) => c[1])
            const width = Math.max(...us) - Math.min(...us)
            const height = Math.max(...vs) - Math.min(...vs)
            const padding = 48

            this.baseK = Math.max(0.5, Math.min((rect.width - padding * 2) / width, (rect.height - padding * 2) / height))
            k = this.baseK
            this.zoom = 1
            this.panX = (rect.width - width * k) / 2 - Math.min(...us) * k
            this.panY = (rect.height - height * k) / 2 - Math.min(...vs) * k

            this.applyView()
        },

        zoomBy(factor, clientX = null, clientY = null) {
            const rect = svg.getBoundingClientRect()
            const px = clientX === null ? rect.width / 2 : clientX - rect.left
            const py = clientY === null ? rect.height / 2 : clientY - rect.top
            const next = clamp(k * factor, this.baseK * 0.2, this.baseK * 12)
            const ratio = next / k

            this.panX = px - (px - this.panX) * ratio
            this.panY = py - (py - this.panY) * ratio
            k = next
            this.zoom = k / this.baseK

            this.applyView()
        },

        centreOn(object, zoom = 3) {
            const rect = svg.getBoundingClientRect()
            const [u, v] = this.project(object.x, object.y, object.height / 2)

            k = clamp(this.baseK * zoom, this.baseK * 0.2, this.baseK * 12)
            this.zoom = k / this.baseK
            this.panX = rect.width / 2 - u * k
            this.panY = rect.height / 2 - v * k

            this.applyView()
        },

        applyView() {
            if (!layers.viewport) return

            layers.viewport.setAttribute('transform', `translate(${this.panX} ${this.panY}) scale(${k})`)
            svg.dataset.labels = this.labels && k >= LABEL_MIN_PX_PER_M ? 'on' : 'off'

            this.renderOverlay()
            this.placeCallout()
        },

        onWheel(event) {
            this.stopFlight()
            this.zoomBy(event.deltaY < 0 ? 1.15 : 1 / 1.15, event.clientX, event.clientY)
        },

        // ---- locate: fly to a desk and light it up -----------------------

        /** The desk a "Locate on map" link named, once the map has drawn. */
        locateFromLink() {
            const target = config.locate

            if (!target) return

            const object = target.desk ? this.objectForDesk(target.desk) : null

            if (object) {
                setTimeout(() => this.locate(object), 150)

                return
            }

            const desk = target.desk ? desks.get(target.desk) : null

            this.notify(desk
                ? `${desk.name} is on this floor but not on its map yet.`
                : `No workstation “${target.term}” on this floor.`)
        },

        objectForDesk(deskId) {
            return [...objects.values()].find((object) => object.workstation_id === Number(deskId)) || null
        },

        locate(object) {
            this.select([object.key])
            this.flyTo(object)
            this.glow(object)
        },

        /**
         * Pan and zoom to an object over a moment rather than jumping, so
         * whoever is looking sees where on the floor it is, not just that it
         * is now in the middle. Zoom is interpolated geometrically and the
         * point at the centre of the screen travels in a straight line.
         */
        flyTo(object, zoom = LOCATE_ZOOM) {
            this.stopFlight()

            const rect = svg.getBoundingClientRect()
            const [u, v] = this.project(object.x, object.y, object.height / 2)
            const toK = clamp(this.baseK * zoom, this.baseK * 0.2, this.baseK * 12)
            const fromK = k
            const fromU = (rect.width / 2 - this.panX) / k
            const fromV = (rect.height / 2 - this.panY) / k
            const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
            const start = performance.now()

            const frameAt = (now) => {
                const t = reduced ? 1 : clamp((now - start) / LOCATE_FLIGHT_MS, 0, 1)
                const e = t < 0.5 ? 4 * t * t * t : 1 - (-2 * t + 2) ** 3 / 2

                k = fromK * (toK / fromK) ** e
                this.zoom = k / this.baseK
                this.panX = rect.width / 2 - (fromU + (u - fromU) * e) * k
                this.panY = rect.height / 2 - (fromV + (v - fromV) * e) * k
                this.applyView()

                flight = t < 1 ? requestAnimationFrame(frameAt) : null
            }

            flight = requestAnimationFrame(frameAt)
        },

        stopFlight() {
            if (flight) cancelAnimationFrame(flight)
            flight = null
        },

        /** A pulsing ring round the object and its name above it, for a few seconds. */
        glow(object) {
            clearTimeout(glowTimer)
            glowing = object.key
            this.renderGlow()

            glowTimer = setTimeout(() => this.clearGlow(), LOCATE_GLOW_MS)
        },

        clearGlow() {
            clearTimeout(glowTimer)
            glowing = null
            this.renderGlow()
        },

        /**
         * Drawn in floor coordinates inside the viewport, so panning and zooming
         * move it without redrawing it — a redraw would restart the pulse.
         */
        renderGlow() {
            if (!layers.glow) return

            const object = glowing ? objects.get(glowing) : null

            if (!object) {
                layers.glow.innerHTML = ''
                this.callout = null

                return
            }

            const radius = Math.max(object.width, object.depth) * 0.75 + 0.5
            const ring = (r) => Array.from({ length: 40 }, (_, i) => {
                const a = (i / 40) * Math.PI * 2

                return this.project(object.x + Math.cos(a) * r, object.y + Math.sin(a) * r, 0).join(',')
            }).join(' ')

            layers.glow.innerHTML =
                `<polygon points="${ring(radius)}" class="fm-glow-core" />`
                + `<polygon points="${ring(radius)}" class="fm-glow-pulse" />`
                + `<polygon points="${ring(radius)}" class="fm-glow-pulse fm-glow-pulse--late" />`

            const desk = object.workstation_id ? desks.get(object.workstation_id) : null

            this.callout = {
                name: desk?.name ?? object.label ?? this.typeLabel(object.type),
                statusLabel: desk?.statusLabel ?? null,
                statusColor: desk?.statusColor ?? null,
                left: 0,
                top: 0,
            }
            this.placeCallout()
        },

        placeCallout() {
            const object = glowing ? objects.get(glowing) : null

            if (!object || !this.callout) return

            const [u, v] = this.project(object.x, object.y, object.height || 0)
            const lift = (this.projection === 'iso' ? 0 : Math.max(object.width, object.depth) / 2) * k

            this.callout.left = Math.round(u * k + this.panX)
            this.callout.top = Math.round(v * k + this.panY - lift - 14)
        },

        // ---- rendering ---------------------------------------------------

        renderPlane() {
            const [a, b, c, d] = this.projection === 'iso' ? [COS30, SIN30, -COS30, SIN30] : [1, 0, 0, 1]
            let html = `<g transform="matrix(${a} ${b} ${c} ${d} 0 0)">`

            html += `<rect class="fm-floor" x="0" y="0" width="${floor.width}" height="${floor.depth}" />`

            if (floor.planUrl) {
                html += `<image href="${escapeHtml(floor.planUrl)}" x="0" y="0" width="${floor.width}" height="${floor.depth}" preserveAspectRatio="none" class="fm-drawing" />`
            }

            html += '<g class="fm-grid">'
            for (let x = 1; x < floor.width; x++) {
                html += `<line x1="${x}" y1="0" x2="${x}" y2="${floor.depth}" class="${x % 5 === 0 ? 'fm-grid-major' : ''}" />`
            }
            for (let y = 1; y < floor.depth; y++) {
                html += `<line x1="0" y1="${y}" x2="${floor.width}" y2="${y}" class="${y % 5 === 0 ? 'fm-grid-major' : ''}" />`
            }
            html += '</g>'

            html += `<rect class="fm-floor-edge" x="0" y="0" width="${floor.width}" height="${floor.depth}" />`
            html += '</g>'

            layers.plane.innerHTML = html
            layers.plane.dataset.grid = this.grid ? 'on' : 'off'
        },

        toggleGrid() {
            this.grid = !this.grid
            layers.plane.dataset.grid = this.grid ? 'on' : 'off'
        },

        /** Everything, in drawing order. */
        renderAll() {
            const sorted = [...objects.values()].sort((a, b) => {
                const layer = (types[a.type]?.layer ?? 10) - (types[b.type]?.layer ?? 10)

                if (layer !== 0) return layer

                // Nearer the viewer is drawn later: lower on screen in iso.
                return this.projection === 'iso' ? a.x + a.y - (b.x + b.y) : a.z - b.z
            })

            layers.objects.innerHTML = sorted.map((object) => this.objectMarkup(object)).join('')
            this.renderGlow()

            this.counts = {
                objects: objects.size,
                placed: [...objects.values()].filter((object) => object.workstation_id).length,
                desks: desks.size,
            }

            this.renderOverlay()
        },

        /** Only these objects: one replaced <g> each. */
        renderKeys(keys) {
            for (const key of keys) {
                const element = layers.objects.querySelector(`[data-key="${key}"]`)
                const object = objects.get(key)

                if (!object) {
                    element?.remove()
                    continue
                }

                if (element) {
                    element.insertAdjacentHTML('afterend', this.objectMarkup(object))
                    element.remove()
                } else {
                    layers.objects.insertAdjacentHTML('beforeend', this.objectMarkup(object))
                }
            }

            if (glowing && keys.includes(glowing)) this.renderGlow()

            this.renderOverlay()
        },

        scheduleRender(keys) {
            if (frame) return

            frame = requestAnimationFrame(() => {
                frame = null
                this.renderKeys(keys)
            })
        },

        objectMarkup(object) {
            const type = types[object.type]

            if (!type) return ''

            const desk = object.workstation_id ? desks.get(object.workstation_id) : null
            const title = desk ? `${desk.name} — ${desk.statusLabel}` : object.label || type.label
            const renderer = RENDERERS[type.renderer] || RENDERERS.box

            return `<g data-key="${object.key}" class="fm-object fm-${type.renderer}${object.locked ? ' is-locked' : ''}"${desk ? ` data-desk="${desk.id}"` : ''}><title>${escapeHtml(title)}</title>${renderer(this, object, type, desk)}</g>`
        },

        /** A solid block in object-local coordinates, drawn in the current projection. */
        prism(object, lx0, ly0, lx1, ly1, z0, z1, color, extraClass = '') {
            const local = [[lx0, ly0], [lx1, ly0], [lx1, ly1], [lx0, ly1]]
            const base = local.map(([lx, ly]) => this.localToWorld(object, lx, ly))

            if (this.projection !== 'iso' || z1 <= z0) {
                const points = base.map((p) => this.project(p.x, p.y, z1).join(',')).join(' ')

                return `<polygon points="${points}" fill="${color}" class="fm-solid ${extraClass}" />`
            }

            const top = base.map((p) => this.project(p.x, p.y, z1))
            const bottom = base.map((p) => this.project(p.x, p.y, z0))
            const r = rad(object.rotation)
            const normals = [[0, -1], [1, 0], [0, 1], [-1, 0]].map(([nx, ny]) => [
                nx * Math.cos(r) - ny * Math.sin(r),
                nx * Math.sin(r) + ny * Math.cos(r),
            ])

            let html = ''

            normals.forEach(([nx, ny], i) => {
                // A side faces the viewer when it faces towards +x +y.
                if (nx + ny <= 0.001) return

                const j = (i + 1) % 4
                const points = [bottom[i], bottom[j], top[j], top[i]].map((p) => p.join(',')).join(' ')

                html += `<polygon points="${points}" fill="${shade(color, nx > ny ? 0.8 : 0.62)}" class="fm-solid ${extraClass}" />`
            })

            html += `<polygon points="${top.map((p) => p.join(',')).join(' ')}" fill="${color}" class="fm-solid fm-top ${extraClass}" />`

            return html
        },

        labelMarkup(object, text, lx = 0, ly = 0, z = 0, size = 0.32, extraClass = '') {
            if (!text) return ''

            const p = this.localToWorld(object, lx, ly)
            const [u, v] = this.project(p.x, p.y, z)
            const rotate = this.projection === 'top' && object.type === 'text' ? ` transform="rotate(${object.rotation} ${u} ${v})"` : ''

            return `<text x="${u}" y="${v}" font-size="${size}" class="fm-label ${extraClass}" text-anchor="middle" dominant-baseline="central"${rotate}>${escapeHtml(text)}</text>`
        },

        renderOverlay() {
            if (!layers.overlay) return

            const handle = 7 / k
            let html = ''

            for (const key of this.selection) {
                const object = objects.get(key)

                if (!object) continue

                const corners = [[-1, -1], [1, -1], [1, 1], [-1, 1]].map(([sx, sy]) =>
                    this.localToWorld(object, (sx * object.width) / 2, (sy * object.depth) / 2),
                )
                const points = corners.map((p) => this.project(p.x, p.y, 0).join(',')).join(' ')

                html += `<polygon points="${points}" class="fm-selection" />`

                if (this.selection.length !== 1 || !this.isEditing || object.locked) continue

                if (types[object.type]?.resizable) {
                    corners.forEach((p, i) => {
                        const [u, v] = this.project(p.x, p.y, 0)
                        html += `<rect data-handle="corner-${i}" x="${u - handle}" y="${v - handle}" width="${handle * 2}" height="${handle * 2}" class="fm-handle" />`
                    })
                }

                const edge = this.localToWorld(object, 0, -object.depth / 2)
                const knob = this.localToWorld(object, 0, -object.depth / 2 - Math.max(0.6, 28 / k))
                const [eu, ev] = this.project(edge.x, edge.y, 0)
                const [ru, rv] = this.project(knob.x, knob.y, 0)

                html += `<line x1="${eu}" y1="${ev}" x2="${ru}" y2="${rv}" class="fm-rotate-arm" />`
                html += `<circle data-handle="rotate" cx="${ru}" cy="${rv}" r="${handle * 1.1}" class="fm-handle fm-handle-rotate" />`
            }

            if (interaction?.kind === 'wall' || interaction?.kind === 'room' || interaction?.kind === 'fill') {
                const { start, end } = interaction

                if (end) {
                    if (interaction.kind === 'wall') {
                        const [u1, v1] = this.project(start.x, start.y)
                        const [u2, v2] = this.project(end.x, end.y)
                        html += `<line x1="${u1}" y1="${v1}" x2="${u2}" y2="${v2}" class="fm-draft-line" />`
                    } else {
                        const box = [
                            [start.x, start.y], [end.x, start.y], [end.x, end.y], [start.x, end.y],
                        ].map(([x, y]) => this.project(x, y).join(',')).join(' ')
                        html += `<polygon points="${box}" class="fm-draft-box" />`
                    }
                }
            }

            layers.overlay.innerHTML = html
        },

        // ---- selection and panel ----------------------------------------

        select(keys, additive = false) {
            this.selection = additive
                ? [...new Set([...this.selection, ...keys])]
                : [...keys]

            this.syncPanel()
            this.renderOverlay()
        },

        clearSelection() {
            this.selection = []
            this.panel = null
            this.renderOverlay()
        },

        syncPanel() {
            this.selection = this.selection.filter((key) => objects.has(key))

            if (this.selection.length !== 1) {
                this.panel = this.selection.length ? { multiple: this.selection.length } : null

                return
            }

            const object = objects.get(this.selection[0])
            const type = types[object.type]
            const desk = object.workstation_id ? desks.get(object.workstation_id) : null

            this.panel = {
                key: object.key,
                type: object.type,
                typeLabel: type.label,
                resizable: type.resizable,
                props: type.props,
                label: object.label ?? '',
                x: round(object.x, 2),
                y: round(object.y, 2),
                z: round(object.z, 2),
                width: round(object.width, 2),
                depth: round(object.depth, 2),
                height: round(object.height, 2),
                rotation: round(object.rotation, 1),
                locked: object.locked,
                color: object.props.color ?? type.color,
                text: object.props.text ?? '',
                size: object.props.size ?? 0.5,
                swing: object.props.swing ?? 'left',
                desk,
            }
        },

        /** A value typed into the properties panel. */
        setField(field, value) {
            if (!this.isEditing || this.selection.length !== 1) return

            const key = this.selection[0]

            this.change(`Change ${field}`, [key], () => {
                const object = objects.get(key)
                const type = types[object.type]

                if (['x', 'y', 'z', 'height'].includes(field)) {
                    object[field] = round(Number(value) || 0)
                } else if (field === 'width' || field === 'depth') {
                    object[field] = clamp(round(Number(value) || 0), type.minSize, type.maxSize)
                } else if (field === 'rotation') {
                    object.rotation = normAngle(round(Number(value) || 0, 2))
                } else if (field === 'label') {
                    object.label = String(value).slice(0, 100) || null
                } else if (field === 'locked') {
                    object.locked = Boolean(value)
                } else if (['color', 'text', 'size', 'swing'].includes(field)) {
                    object.props = { ...object.props, [field]: field === 'size' ? clamp(Number(value) || 0.5, 0.1, 10) : value }
                }
            })
        },

        openDetails(deskId) {
            if (!deskId) return

            this.$wire.mountAction('deskDetails', { workstation: deskId })
        },

        // ---- history -----------------------------------------------------

        snapshot(keys) {
            return keys.map((key) => [key, objects.has(key) ? clone(objects.get(key)) : null])
        },

        /** Run a change and put it on the undo stack. */
        change(label, keys, mutate) {
            const before = this.snapshot(keys)
            const created = mutate() || []
            const allKeys = [...new Set([...keys, ...created])]
            const after = this.snapshot(allKeys)

            this.record(label, [...before, ...this.snapshot(created).map(([key]) => [key, null])], after)
            this.renderAll()
            this.syncPanel()
            this.refreshTray()
        },

        record(label, before, after) {
            const changed = after.some(([key, value]) => !sameObject(value, before.find(([k]) => k === key)?.[1] ?? null))
                || before.some(([key, value]) => value && !after.find(([k]) => k === key)?.[1])

            if (!changed) return

            history.undo.push({ label, before, after })

            if (history.undo.length > HISTORY_LIMIT) history.undo.shift()

            history.redo = []
            this.dirty = true
            this.updateHistoryFlags()
        },

        restore(entries) {
            for (const [key, value] of entries) {
                if (value) {
                    objects.set(key, clone(value))
                } else {
                    objects.delete(key)
                }
            }
        },

        undo() {
            const entry = history.undo.pop()

            if (!entry) return

            this.restore(entry.after.map(([key]) => [key, null]))
            this.restore(entry.before.filter(([, value]) => value))
            history.redo.push(entry)
            this.afterHistory()
        },

        redo() {
            const entry = history.redo.pop()

            if (!entry) return

            this.restore(entry.before.map(([key]) => [key, null]))
            this.restore(entry.after.filter(([, value]) => value))
            history.undo.push(entry)
            this.afterHistory()
        },

        afterHistory() {
            this.dirty = true
            this.updateHistoryFlags()
            this.renderAll()
            this.syncPanel()
            this.refreshTray()
        },

        updateHistoryFlags() {
            this.canUndo = history.undo.length > 0
            this.canRedo = history.redo.length > 0
        },

        // ---- creating objects ------------------------------------------

        makeObject(typeKey, x, y, overrides = {}) {
            const type = types[typeKey]
            const object = {
                id: null,
                type: typeKey,
                workstation_id: null,
                label: null,
                x: round(x),
                y: round(y),
                z: 0,
                width: type.width,
                depth: type.depth,
                height: type.height,
                rotation: 0,
                props: {},
                locked: false,
                ...overrides,
            }

            if (typeKey === 'emergency-exit' && !object.props.text) object.props.text = 'EXIT'
            if (typeKey === 'text' && !object.props.text) object.props.text = 'Label'
            if (typeKey === 'door' && !object.props.swing) object.props.swing = 'left'

            object.key = `n${nextKey++}`

            return object
        },

        addObjects(label, list) {
            if (!list.length) return

            this.change(label, [], () => {
                for (const object of list) objects.set(object.key, object)

                return list.map((object) => object.key)
            })

            this.select(list.map((object) => object.key))
        },

        // ---- the tray: desks not on the map ---------------------------

        refreshTray() {
            const onMap = new Set([...objects.values()].map((object) => object.workstation_id).filter(Boolean))

            this.tray = [...desks.values()]
                .filter((desk) => !onMap.has(desk.id))
                .sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true }))
                .map((desk) => ({ id: desk.id, name: desk.name }))

            this.counts = { ...this.counts, placed: onMap.size, objects: objects.size }
        },

        placeDesk(deskId, x, y, rotation = 0) {
            const desk = desks.get(deskId)

            if (!desk || [...objects.values()].some((object) => object.workstation_id === deskId)) return null

            return this.makeObject('workstation', this.snapValue(x), this.snapValue(y), { workstation_id: deskId, rotation })
        },

        pickDesk(deskId) {
            if (!this.isEditing) return

            this.tool = 'workstation'
            this.pendingDesk = deskId
        },

        async createDesk() {
            const name = (this.newDesk?.name || '').trim()

            if (!name) return

            this.newDesk.busy = true

            const result = await this.$wire.createDesk(name)

            if (!result?.ok) {
                this.newDesk.busy = false
                this.newDesk.error = result?.message || 'The desk could not be created.'

                return
            }

            desks.set(result.desk.id, result.desk)

            const { x, y } = this.newDesk.at
            const object = this.placeDesk(result.desk.id, x, y)

            this.newDesk = null

            if (object) this.addObjects(`Add ${result.desk.name}`, [object])
        },

        // ---- pointer handling -----------------------------------------

        onPointerDown(event) {
            if (event.button === 2) return

            this.stopFlight()

            svg.setPointerCapture?.(event.pointerId)

            const world = this.toWorld(event.clientX, event.clientY)
            const handle = event.target.closest('[data-handle]')?.dataset.handle
            const key = event.target.closest('[data-key]')?.dataset.key
            const panning = event.button === 1 || spaceHeld

            if (panning) {
                interaction = { kind: 'pan', startX: event.clientX, startY: event.clientY, panX: this.panX, panY: this.panY }

                return
            }

            // ---- viewing ----
            if (!this.isEditing) {
                interaction = { kind: 'pan', startX: event.clientX, startY: event.clientY, panX: this.panX, panY: this.panY, click: key }

                return
            }

            // ---- handles of the selected object ----
            if (handle && this.selection.length === 1) {
                const object = objects.get(this.selection[0])

                interaction = {
                    kind: handle === 'rotate' ? 'rotate' : 'resize',
                    corner: handle.startsWith('corner') ? Number(handle.split('-')[1]) : null,
                    key: object.key,
                    before: this.snapshot([object.key]),
                }

                return
            }

            // ---- placing tools ----
            if (this.tool === 'wall' || this.tool === 'room' || this.tool === 'fill'
                || this.tool === 'meeting-room' || this.tool === 'it-room') {
                const start = { x: this.snapValue(world.x), y: this.snapValue(world.y) }
                const kind = this.tool === 'wall' ? 'wall' : this.tool === 'fill' ? 'fill' : 'room'

                interaction = { kind, type: this.tool, start, end: null }

                return
            }

            if (this.tool !== 'select') {
                interaction = { kind: 'place', type: this.tool, world }

                return
            }

            // ---- selecting and moving ----
            if (key) {
                const additive = event.shiftKey || event.ctrlKey || event.metaKey

                if (additive && this.selection.includes(key)) {
                    this.select(this.selection.filter((k) => k !== key))

                    return
                }

                if (!this.selection.includes(key)) this.select([key], additive)

                const moving = this.selection.filter((k) => !objects.get(k)?.locked)

                interaction = {
                    kind: 'move',
                    keys: moving,
                    anchor: world,
                    primary: key,
                    origin: Object.fromEntries(moving.map((k) => [k, { x: objects.get(k).x, y: objects.get(k).y }])),
                    before: this.snapshot(moving),
                    moved: false,
                    startX: event.clientX,
                    startY: event.clientY,
                }

                return
            }

            if (event.shiftKey) {
                interaction = { kind: 'marquee', startX: event.clientX, startY: event.clientY }

                return
            }

            interaction = { kind: 'pan', startX: event.clientX, startY: event.clientY, panX: this.panX, panY: this.panY, deselect: true }
        },

        onPointerMove(event) {
            const world = this.toWorld(event.clientX, event.clientY)

            this.cursor = { x: round(world.x, 2), y: round(world.y, 2) }

            if (this.ghost) {
                this.ghost = { ...this.ghost, left: event.clientX, top: event.clientY }
            }

            if (!interaction) return

            const it = interaction

            if (it.kind === 'pan') {
                const dx = event.clientX - it.startX
                const dy = event.clientY - it.startY

                if (Math.abs(dx) + Math.abs(dy) > 3) it.dragged = true

                this.panX = it.panX + dx
                this.panY = it.panY + dy
                this.applyView()

                return
            }

            if (it.kind === 'move') {
                if (!it.moved && Math.hypot(event.clientX - it.startX, event.clientY - it.startY) < 4) return

                it.moved = true

                const primary = it.origin[it.primary] || Object.values(it.origin)[0]

                if (!primary) return

                const targetX = this.snapValue(primary.x + (world.x - it.anchor.x))
                const targetY = this.snapValue(primary.y + (world.y - it.anchor.y))
                const dx = targetX - primary.x
                const dy = targetY - primary.y

                for (const key of it.keys) {
                    const object = objects.get(key)
                    object.x = round(it.origin[key].x + dx)
                    object.y = round(it.origin[key].y + dy)
                }

                this.scheduleRender(it.keys)

                return
            }

            if (it.kind === 'rotate') {
                const object = objects.get(it.key)
                let angle = (Math.atan2(world.y - object.y, world.x - object.x) * 180) / Math.PI + 90

                angle = event.shiftKey ? angle : Math.round(angle / 15) * 15
                object.rotation = normAngle(round(angle, 2))
                this.scheduleRender([it.key])

                return
            }

            if (it.kind === 'resize') {
                const object = objects.get(it.key)
                const type = types[object.type]
                const original = it.before[0][1]
                const signs = [[-1, -1], [1, -1], [1, 1], [-1, 1]][it.corner]
                const opposite = this.localToWorld(original, (-signs[0] * original.width) / 2, (-signs[1] * original.depth) / 2)
                const r = rad(-original.rotation)
                const dx = world.x - opposite.x
                const dy = world.y - opposite.y
                const qx = (dx * Math.cos(r) - dy * Math.sin(r)) * signs[0]
                const qy = (dx * Math.sin(r) + dy * Math.cos(r)) * signs[1]
                const width = clamp(this.snapValue(Math.max(qx, 0)), type.minSize, type.maxSize)
                const depth = clamp(this.snapValue(Math.max(qy, 0)), type.minSize, type.maxSize)
                const centre = this.localToWorld(
                    { ...original, x: opposite.x, y: opposite.y },
                    (signs[0] * width) / 2,
                    (signs[1] * depth) / 2,
                )

                Object.assign(object, { width: round(width), depth: round(depth), x: round(centre.x), y: round(centre.y) })
                this.scheduleRender([it.key])

                return
            }

            if (it.kind === 'wall' || it.kind === 'room' || it.kind === 'fill') {
                let end = { x: this.snapValue(world.x), y: this.snapValue(world.y) }

                if (it.kind === 'wall' && !event.shiftKey) {
                    // Walls are drawn at 15° steps unless Shift says otherwise.
                    const length = Math.hypot(end.x - it.start.x, end.y - it.start.y)
                    const angle = Math.round(Math.atan2(end.y - it.start.y, end.x - it.start.x) / rad(15)) * rad(15)
                    end = { x: round(it.start.x + Math.cos(angle) * length), y: round(it.start.y + Math.sin(angle) * length) }
                }

                it.end = end
                this.renderOverlay()

                return
            }

            if (it.kind === 'marquee') {
                const rect = svg.getBoundingClientRect()

                this.marquee = {
                    left: Math.min(it.startX, event.clientX) - rect.left,
                    top: Math.min(it.startY, event.clientY) - rect.top,
                    width: Math.abs(event.clientX - it.startX),
                    height: Math.abs(event.clientY - it.startY),
                }
            }
        },

        onPointerUp(event) {
            const it = interaction

            interaction = null

            if (this.ghost) {
                this.dropGhost(event)

                return
            }

            if (!it) return

            if (it.kind === 'pan') {
                if (!it.dragged && it.click) {
                    this.clickObject(it.click)
                } else if (!it.dragged && it.deselect) {
                    this.clearSelection()
                }

                return
            }

            if (it.kind === 'move') {
                if (!it.moved) {
                    return
                }

                this.record(it.keys.length > 1 ? `Move ${it.keys.length} objects` : 'Move', it.before, this.snapshot(it.keys))
                this.renderAll()
                this.syncPanel()

                return
            }

            if (it.kind === 'rotate' || it.kind === 'resize') {
                this.record(it.kind === 'rotate' ? 'Rotate' : 'Resize', it.before, this.snapshot([it.key]))
                this.renderAll()
                this.syncPanel()

                return
            }

            if (it.kind === 'place') {
                this.placeAt(it.type, it.world)

                return
            }

            if (it.kind === 'wall') {
                const end = it.end ?? it.start
                const length = Math.hypot(end.x - it.start.x, end.y - it.start.y)

                const wall = length < 0.2
                    ? this.makeObject('wall', it.start.x, it.start.y)
                    : this.makeObject('wall', (it.start.x + end.x) / 2, (it.start.y + end.y) / 2, {
                        width: round(length),
                        rotation: normAngle(round((Math.atan2(end.y - it.start.y, end.x - it.start.x) * 180) / Math.PI, 2)),
                    })

                this.addObjects('Add wall', [wall])
                this.renderOverlay()

                return
            }

            if (it.kind === 'room') {
                const end = it.end ?? it.start
                const width = Math.abs(end.x - it.start.x)
                const depth = Math.abs(end.y - it.start.y)
                const room = width < 0.5 || depth < 0.5
                    ? this.makeObject(it.type, it.start.x, it.start.y)
                    : this.makeObject(it.type, (it.start.x + end.x) / 2, (it.start.y + end.y) / 2, { width: round(width), depth: round(depth) })

                this.addObjects(`Add ${types[it.type].label.toLowerCase()}`, [room])
                this.renderOverlay()

                return
            }

            if (it.kind === 'fill') {
                const end = it.end ?? it.start
                const width = Math.abs(end.x - it.start.x)
                const depth = Math.abs(end.y - it.start.y)

                if (width < 1 || depth < 1) {
                    this.renderOverlay()

                    return
                }

                const desk = types.workstation

                this.fill = {
                    box: { x0: Math.min(it.start.x, end.x), y0: Math.min(it.start.y, end.y), x1: Math.max(it.start.x, end.x), y1: Math.max(it.start.y, end.y) },
                    columns: Math.max(1, Math.floor(width / (desk.width + 0.2))),
                    rows: Math.max(1, Math.floor(depth / (desk.depth + 0.2))),
                    rotation: 0,
                }

                this.renderOverlay()

                return
            }

            if (it.kind === 'marquee') {
                this.selectInMarquee(event.shiftKey)
                this.marquee = null
            }
        },

        clickObject(key) {
            const object = objects.get(key)

            if (!object) return

            if (object.workstation_id) {
                this.openDetails(object.workstation_id)

                return
            }

            this.select([key])
        },

        onDoubleClick(event) {
            const key = event.target.closest('[data-key]')?.dataset.key
            const object = key ? objects.get(key) : null

            if (object?.workstation_id) this.openDetails(object.workstation_id)
        },

        placeAt(typeKey, world) {
            if (typeKey === 'workstation') {
                const deskId = this.pendingDesk ?? this.tray[0]?.id

                if (!deskId) {
                    if (this.canCreateDesks) {
                        this.newDesk = { name: '', at: world, busy: false, error: null }
                    }

                    return
                }

                const object = this.placeDesk(deskId, world.x, world.y)

                this.pendingDesk = null

                if (object) this.addObjects(`Place ${desks.get(deskId).name}`, [object])

                return
            }

            this.addObjects(`Add ${types[typeKey].label.toLowerCase()}`, [
                this.makeObject(typeKey, this.snapValue(world.x), this.snapValue(world.y)),
            ])
        },

        selectInMarquee(additive) {
            if (!this.marquee) return

            const { left, top, width, height } = this.marquee
            const keys = [...objects.values()]
                .filter((object) => {
                    const [u, v] = this.project(object.x, object.y)
                    const sx = u * k + this.panX
                    const sy = v * k + this.panY

                    return sx >= left && sx <= left + width && sy >= top && sy <= top + height
                })
                .map((object) => object.key)

            this.select(keys, additive)
        },

        // ---- dragging a desk in from the tray ---------------------------

        startTrayDrag(event, desk) {
            if (!this.isEditing) return

            event.preventDefault()
            this.ghost = { id: desk.id, name: desk.name, left: event.clientX, top: event.clientY }
        },

        dropGhost(event) {
            const ghost = this.ghost
            const rect = svg.getBoundingClientRect()

            this.ghost = null

            if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) {
                return
            }

            const world = this.toWorld(event.clientX, event.clientY)
            const object = this.placeDesk(ghost.id, world.x, world.y)

            if (object) this.addObjects(`Place ${ghost.name}`, [object])
        },

        // ---- editing actions ------------------------------------------

        deleteSelection() {
            if (!this.isEditing) return

            const keys = this.selection.filter((key) => !objects.get(key)?.locked)

            if (!keys.length) return

            this.change(keys.length > 1 ? `Delete ${keys.length} objects` : 'Delete', keys, () => {
                for (const key of keys) objects.delete(key)
            })

            this.clearSelection()
        },

        rotateSelection(degrees) {
            if (!this.isEditing) return

            const keys = this.selection.filter((key) => !objects.get(key)?.locked)

            if (!keys.length) return

            this.change('Rotate', keys, () => {
                for (const key of keys) {
                    const object = objects.get(key)
                    object.rotation = normAngle(object.rotation + degrees)
                }
            })
        },

        nudgeSelection(dx, dy) {
            const keys = this.selection.filter((key) => !objects.get(key)?.locked)

            if (!keys.length) return

            this.change('Nudge', keys, () => {
                for (const key of keys) {
                    const object = objects.get(key)
                    object.x = round(object.x + dx)
                    object.y = round(object.y + dy)
                }
            })
        },

        /**
         * Copies beside the originals. A desk's place on the map is the place
         * of one desk record, so a copied workstation takes the next desk from
         * the tray instead — or is skipped when the tray is empty.
         */
        duplicateSelection() {
            if (!this.isEditing || !this.selection.length) return

            const offset = Math.max(this.step, 0.5) * 2
            const free = [...this.tray]
            const copies = []
            let skipped = 0

            for (const key of this.selection) {
                const original = objects.get(key)
                const overrides = { ...clone(original), id: null, x: round(original.x + offset), y: round(original.y + offset), locked: false }

                delete overrides.key

                if (original.workstation_id) {
                    const desk = free.shift()

                    if (!desk) {
                        skipped++
                        continue
                    }

                    overrides.workstation_id = desk.id
                }

                copies.push(this.makeObject(original.type, overrides.x, overrides.y, overrides))
            }

            this.addObjects(copies.length > 1 ? `Duplicate ${copies.length} objects` : 'Duplicate', copies)

            if (skipped) {
                this.notify(`${skipped} workstation(s) not copied: every desk on this floor is already on the map.`)
            }
        },

        selectAll() {
            this.select([...objects.keys()])
        },

        applyFill() {
            if (!this.fill) return

            const { box, rotation } = this.fill
            const columns = clamp(Number(this.fill.columns) || 1, 1, 100)
            const rows = clamp(Number(this.fill.rows) || 1, 1, 100)
            const desk = types.workstation
            const sideways = rotation % 180 !== 0
            const halfW = (sideways ? desk.depth : desk.width) / 2
            const halfD = (sideways ? desk.width : desk.depth) / 2
            const x0 = box.x0 + halfW
            const x1 = Math.max(x0, box.x1 - halfW)
            const y0 = box.y0 + halfD
            const y1 = Math.max(y0, box.y1 - halfD)
            const free = this.tray.slice(0, columns * rows)
            const placed = free.map((item, index) => {
                const column = index % columns
                const row = Math.floor(index / columns)
                const x = columns > 1 ? x0 + ((x1 - x0) * column) / (columns - 1) : (x0 + x1) / 2
                const y = rows > 1 ? y0 + ((y1 - y0) * row) / (rows - 1) : (y0 + y1) / 2

                return this.makeObject('workstation', round(x), round(y), { workstation_id: item.id, rotation })
            })

            this.fill = null
            this.tool = 'select'

            if (!placed.length) {
                this.notify('No desks left in the tray. Use Add many to create more.')

                return
            }

            this.addObjects(`Fill area with ${placed.length} desks`, placed)

            if (placed.length < columns * rows) {
                this.notify(`Placed ${placed.length} of ${columns * rows}: that was every desk left in the tray.`)
            }
        },

        /** Every desk still in the tray, in a grid across the floor. */
        autoArrange() {
            if (!this.isEditing || !this.tray.length) return

            const count = this.tray.length
            const aspect = floor.width / floor.depth
            const columns = Math.max(1, Math.round(Math.sqrt(count * aspect)))
            const rows = Math.ceil(count / columns)
            const margin = floor.width >= 30 ? 0.05 : 0.1
            const spanX = floor.width * (1 - margin * 2)
            const spanY = floor.depth * (1 - margin * 2)

            const placed = this.tray.map((item, index) => {
                const column = index % columns
                const row = Math.floor(index / columns)
                const x = floor.width * margin + (columns > 1 ? (spanX * column) / (columns - 1) : spanX / 2)
                const y = floor.depth * margin + (rows > 1 ? (spanY * row) / (rows - 1) : spanY / 2)

                return this.makeObject('workstation', round(x), round(y), { workstation_id: item.id })
            })

            this.addObjects(`Arrange ${placed.length} desks`, placed)
        },

        /** Every desk off the map and back into the tray. Everything else stays. */
        clearDesks() {
            if (!this.isEditing) return

            const keys = [...objects.values()].filter((object) => object.workstation_id).map((object) => object.key)

            if (!keys.length) return

            this.change(`Take ${keys.length} desks off the map`, keys, () => {
                for (const key of keys) objects.delete(key)
            })

            this.clearSelection()
        },

        // ---- find a desk: this floor first, then the others -------------

        async find() {
            const term = this.search.trim()
            const needle = term.toLowerCase()

            this.searchMiss = false

            if (!needle) return

            const matches = [...desks.values()].filter((desk) =>
                [desk.name, desk.computer, desk.number, desk.port, desk.ip]
                    .some((value) => value && String(value).toLowerCase().includes(needle)),
            )
            const exact = matches.find((desk) => desk.name.toLowerCase() === needle) || matches[0]
            const object = exact ? this.objectForDesk(exact.id) : null

            if (object) {
                this.locate(object)

                return
            }

            // Somebody typing a desk into the map's box means that desk,
            // wherever it is — the server knows the other floors.
            if (!this.canSearch) {
                this.searchMiss = true

                return
            }

            const result = await this.$wire.locateElsewhere(term)

            if (result?.found && result.url) {
                if (this.dirty) {
                    this.notify(`${result.message} Save or undo your changes here to go to it.`)

                    return
                }

                window.location.href = result.url

                return
            }

            this.searchMiss = true

            if (result?.message) this.notify(result.message, result.url && !this.dirty ? result.url : null)
        },

        historyUrl(deskId) {
            return config.historyUrl ? config.historyUrl.replace('__DESK__', encodeURIComponent(deskId)) : null
        },

        // ---- saving ----------------------------------------------------

        payload() {
            return [...objects.values()].map((object) => ({
                id: object.id,
                type: object.type,
                workstation_id: object.workstation_id,
                label: object.label,
                x: object.x,
                y: object.y,
                z: object.z,
                width: object.width,
                depth: object.depth,
                height: object.height,
                rotation: object.rotation,
                props: object.props,
                locked: object.locked,
            }))
        },

        async save() {
            if (!this.isEditing || this.saving || !this.dirty) return

            this.saving = true

            try {
                const result = await this.$wire.saveMap(revision, this.payload())

                if (result?.reason === 'conflict') {
                    this.conflict = true

                    return
                }

                if (!result?.ok) return

                revision = result.revision
                load(result.objects)
                history.undo = []
                history.redo = []
                this.updateHistoryFlags()
                this.dirty = false
                this.clearSelection()
                this.renderAll()
                this.refreshTray()
            } finally {
                this.saving = false
            }
        },

        reload() {
            this.dirty = false
            window.location.reload()
        },

        goToFloor(url) {
            if (!url) return
            window.location.href = url
        },

        notify(message, url = null) {
            const notification = new FilamentNotification().title(message).warning()

            if (url && window.FilamentNotificationAction) {
                notification.actions([new FilamentNotificationAction('search').label('Show the matches').button().url(url)])
            }

            notification.send()
        },

        // ---- keyboard --------------------------------------------------

        onKey(event) {
            const target = event.target

            if (target.closest?.('input, textarea, select, [contenteditable], .fi-modal')) return

            if (event.code === 'Space') {
                spaceHeld = true

                return
            }

            const ctrl = event.ctrlKey || event.metaKey
            const key = event.key.toLowerCase()

            if (ctrl && key === 's') {
                event.preventDefault()
                this.save()

                return
            }

            if (key === 'escape' && glowing) this.clearGlow()

            if (!this.isEditing) return

            if (ctrl && key === 'z' && !event.shiftKey) {
                event.preventDefault()
                this.undo()
            } else if (ctrl && (key === 'y' || (key === 'z' && event.shiftKey))) {
                event.preventDefault()
                this.redo()
            } else if (ctrl && key === 'd') {
                event.preventDefault()
                this.duplicateSelection()
            } else if (ctrl && key === 'a') {
                event.preventDefault()
                this.selectAll()
            } else if (key === 'delete' || key === 'backspace') {
                event.preventDefault()
                this.deleteSelection()
            } else if (key === 'escape') {
                this.fill = null
                this.pendingDesk = null
                this.tool = 'select'
                this.clearSelection()
            } else if (key === 'r' && !ctrl) {
                this.rotateSelection(event.shiftKey ? -90 : 90)
            } else if (key === 'v' && !ctrl) {
                this.setTool('select')
            } else if (key.startsWith('arrow') && this.selection.length) {
                event.preventDefault()

                const distance = (this.snap ? this.step : 0.1) * (event.shiftKey ? 10 : 1)
                const moves = { arrowleft: [-1, 0], arrowright: [1, 0], arrowup: [0, -1], arrowdown: [0, 1] }
                const [dx, dy] = moves[key]

                this.nudgeSelection(dx * distance, dy * distance)
            }
        },
    }
}

// ---- renderers ------------------------------------------------------------
//
// One per shape, each returning SVG for one object in the current projection.
// A registered object type names the renderer that draws it.

const STATUS_FALLBACK = '#94a3b8'

const RENDERERS = {
    workstation(map, object, type, desk) {
        const w = object.width
        const d = object.depth
        const back = -d / 2
        const deskDepth = Math.min(0.75, d * 0.55)
        const top = object.height || 0.75
        const status = desk?.statusColor || STATUS_FALLBACK
        const seat = back + deskDepth + Math.max(0.35, (d - deskDepth) / 2)

        let html = ''

        // Chair first: it is in front of the desk, and drawn under it in plan.
        html += map.prism(object, -0.25, seat - 0.25, 0.25, seat + 0.25, 0, 0.45, '#334155', 'fm-chair')
        html += map.prism(object, -0.25, seat + 0.18, 0.25, seat + 0.26, 0.45, 0.95, '#1e293b', 'fm-chair')

        html += map.prism(object, -w / 2, back, w / 2, back + deskDepth, 0, top, type.color, 'fm-desk-top')
        html += map.prism(object, -0.45, back + deskDepth - 0.28, 0.45, back + deskDepth - 0.12, top, top + 0.02, '#475569', 'fm-keyboard')
        // The monitor's colour is the desk's status.
        html += map.prism(object, -0.3, back + 0.08, 0.3, back + 0.14, top, top + 0.38, status, 'fm-monitor')

        if (desk?.details) {
            const corner = map.localToWorld(object, w / 2 - 0.12, back + 0.12)
            const [u, v] = map.project(corner.x, corner.y, top)
            html += `<circle cx="${u}" cy="${v}" r="0.07" class="fm-details-dot" />`
        }

        html += map.labelMarkup(object, desk?.name ?? '?', 0, back + deskDepth / 2, top + 0.01, 0.3, 'fm-desk-label')

        return html
    },

    desk(map, object, type) {
        return map.prism(object, -object.width / 2, -object.depth / 2, object.width / 2, object.depth / 2, object.z, object.z + (object.height || 0.75), object.props.color || type.color)
            + map.labelMarkup(object, object.label, 0, 0, object.z + object.height + 0.01, 0.3)
    },

    box(map, object, type) {
        return map.prism(object, -object.width / 2, -object.depth / 2, object.width / 2, object.depth / 2, object.z, object.z + object.height, object.props.color || type.color)
            + map.labelMarkup(object, object.label || type.label, 0, 0, object.z + object.height + 0.01, 0.28)
    },

    room(map, object, type) {
        const color = object.props.color || type.color
        const corners = [[-1, -1], [1, -1], [1, 1], [-1, 1]]
            .map(([sx, sy]) => map.localToWorld(object, (sx * object.width) / 2, (sy * object.depth) / 2))
            .map((p) => map.project(p.x, p.y, 0).join(','))
            .join(' ')

        return `<polygon points="${corners}" fill="${color}" stroke="${color}" class="fm-room-area" />`
            + map.labelMarkup(object, object.label || type.label, 0, 0, 0, Math.min(0.6, Math.max(0.3, Math.min(object.width, object.depth) / 6)), 'fm-room-label')
    },

    wall(map, object, type) {
        const height = map.cutaway ? Math.min(object.height, 0.6) : object.height

        return map.prism(object, -object.width / 2, -object.depth / 2, object.width / 2, object.depth / 2, 0, height, object.props.color || type.color, 'fm-wall')
    },

    door(map, object, type) {
        const w = object.width
        const left = (object.props.swing || 'left') === 'left'
        const hinge = left ? -w / 2 : w / 2
        const steps = 10
        const arc = []

        for (let i = 0; i <= steps; i++) {
            const a = ((Math.PI / 2) * i) / steps
            const p = map.localToWorld(object, hinge + (left ? 1 : -1) * w * Math.cos(a), w * Math.sin(a))
            arc.push(map.project(p.x, p.y, 0).join(','))
        }

        const opening = map.prism(object, -w / 2, -object.depth / 2, w / 2, object.depth / 2, 0, 0, '#0f172a', 'fm-door-gap')
        const leafHeight = map.cutaway ? Math.min(object.height, 0.6) : object.height
        const leaf = map.prism(object, hinge - 0.03, 0, hinge + 0.03, w, 0, leafHeight, type.color, 'fm-door-leaf')

        return opening + `<polyline points="${arc.join(' ')}" class="fm-door-swing" />` + leaf
    },

    marker(map, object, type) {
        return map.prism(object, -object.width / 2, -object.depth / 2, object.width / 2, object.depth / 2, 0, 0.02, object.props.color || type.color, 'fm-marker')
            + map.labelMarkup(object, object.props.text || type.label, 0, 0, 0.03, Math.min(0.32, object.depth * 0.6), 'fm-marker-label')
    },

    text(map, object, type) {
        const size = object.props.size || 0.5
        const color = object.props.color || type.color

        return map.labelMarkup(object, object.props.text || object.label || 'Label', 0, 0, 0, size, 'fm-text')
            .replace('class="fm-label fm-text"', `class="fm-label fm-text" fill="${color}"`)
    },
}
