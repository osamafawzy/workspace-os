/**
 * The building, in 3D.
 *
 * Floors are slabs stacked by storey number and sized in real metres; desks are
 * blocks standing on them at their percentage coordinates. Everything the scene
 * knows arrives as one plain object from the server — this file never talks to
 * the API.
 *
 * Built for floors that hold hundreds of desks. The desks on a floor are one
 * InstancedMesh rather than one mesh each: three hundred desks across a few
 * storeys is a thousand draw calls done the obvious way, and the scene stops
 * being interactive long before the building gets interesting.
 */
import * as THREE from 'three'
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js'
import { mountDeskModal } from './desk-modal.js'

const SLAB_THICKNESS = 0.3
const STOREY_HEIGHT = 3.6

const DESK_WIDTH = 1.5
const DESK_DEPTH = 0.8
const DESK_HEIGHT = 0.74

const PALETTE = {
    light: {
        background: 0xf1f5f9,
        slab: 0xffffff,
        slabEdge: 0xcbd5e1,
        desk: 0x4f46e5,
        deskHover: 0xf59e0b,
        label: '#475569',
        ambient: 0xffffff,
    },
    dark: {
        background: 0x121215,
        slab: 0x3f3f46,
        slabEdge: 0x71717a,
        desk: 0x818cf8,
        deskHover: 0xfbbf24,
        label: '#a1a1aa',
        ambient: 0xd4d4d8,
    },
}

/**
 * A floor's name, drawn into a texture and hung off the edge of its slab.
 *
 * A sprite rather than a second CSS renderer over the canvas: it is one file
 * fewer, it sorts with the scene, and the label is wanted in screenshots and
 * anywhere the canvas is captured — which an HTML overlay would not be.
 */
function makeLabel(text, colour) {
    const scale = 3
    const measure = document.createElement('canvas').getContext('2d')
    const font = `600 ${18 * scale}px ui-sans-serif, system-ui, -apple-system, sans-serif`
    measure.font = font

    const width = Math.ceil(measure.measureText(text).width) + 16 * scale
    const height = 30 * scale

    const canvas = document.createElement('canvas')
    canvas.width = width
    canvas.height = height

    const ctx = canvas.getContext('2d')
    ctx.font = font
    ctx.fillStyle = colour
    ctx.textBaseline = 'middle'
    ctx.fillText(text, 8 * scale, height / 2)

    const texture = new THREE.CanvasTexture(canvas)
    texture.anisotropy = 4

    const sprite = new THREE.Sprite(
        new THREE.SpriteMaterial({ map: texture, transparent: true, depthTest: false, depthWrite: false }),
    )
    // Always on top of the geometry: a floor name behind its own slab is
    // worse than useless, it looks like a rendering fault.
    sprite.renderOrder = 10

    return { sprite, aspect: width / height }
}

/**
 * Sizes a label against the *building*, not against its own floor.
 *
 * The camera pulls back far enough to fit the largest floor plate, so a name
 * scaled to a small floor is a few unreadable pixels next to a 60 m one. Every
 * label is the same world size for the same reason road signs are.
 */
function sizeLabel(label, buildingWidth) {
    const worldHeight = Math.max(1.2, buildingWidth / 22)
    label.sprite.scale.set(label.aspect * worldHeight, worldHeight, 1)

    return label.sprite
}

export function mountBuilding(root, payload) {
    const canvasHost = root.querySelector('[data-canvas]')
    const tooltip = root.querySelector('[data-tooltip]')
    const explodeInput = root.querySelector('[data-explode]')
    const resetButton = root.querySelector('[data-reset]')
    const focusLabel = root.querySelector('[data-focus-label]')
    const clearFocusButton = root.querySelector('[data-clear-focus]')
    const floorButtons = [...root.querySelectorAll('[data-floor-button]')]
    const deskModal = mountDeskModal()

    if (!supportsWebGl()) {
        root.querySelector('[data-webgl-missing]')?.removeAttribute('hidden')
        canvasHost?.setAttribute('hidden', '')
        root.querySelector('[data-viewer-controls]')?.setAttribute('hidden', '')

        return
    }

    const floors = payload.floors
    const isDark = () => document.documentElement.classList.contains('dark')
    let colours = PALETTE[isDark() ? 'dark' : 'light']

    // Everything that used to be a hand-picked number is now derived from the
    // biggest floor in the building: lighting, fog, zoom limits, label size.
    // A 60 m floor plate and a 12 m mezzanine cannot share a fixed set.
    const widest = Math.max(...floors.map((f) => Math.max(f.width, f.depth)), 12)

    // ---- scene ------------------------------------------------------------

    const scene = new THREE.Scene()
    scene.background = new THREE.Color(colours.background)
    scene.fog = new THREE.Fog(colours.background, widest * 2.5, widest * 8)

    const camera = new THREE.PerspectiveCamera(45, 1, 0.1, widest * 40)
    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false })
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2))
    renderer.shadowMap.enabled = true
    renderer.shadowMap.type = THREE.PCFSoftShadowMap
    canvasHost.appendChild(renderer.domElement)
    renderer.domElement.style.display = 'block'
    renderer.domElement.style.width = '100%'
    renderer.domElement.style.height = '100%'
    renderer.domElement.style.touchAction = 'none'

    const controls = new OrbitControls(camera, renderer.domElement)
    controls.enableDamping = true
    controls.dampingFactor = 0.08
    controls.minDistance = widest * 0.25
    controls.maxDistance = widest * 8
    // Stops the camera going under the ground floor, where the building is
    // just a set of undersides and nothing reads.
    controls.maxPolarAngle = Math.PI * 0.49

    scene.add(new THREE.HemisphereLight(colours.ambient, 0x404048, 1.6))

    const key = new THREE.DirectionalLight(0xffffff, 1.5)
    key.position.set(widest * 0.9, widest * 1.5, widest * 0.7)
    key.castShadow = true
    key.shadow.mapSize.set(2048, 2048)
    key.shadow.camera.left = -widest
    key.shadow.camera.right = widest
    key.shadow.camera.top = widest * 1.3
    key.shadow.camera.bottom = -widest * 0.8
    key.shadow.camera.far = widest * 6
    scene.add(key)

    const fill = new THREE.DirectionalLight(0xffffff, 0.35)
    fill.position.set(-widest * 0.9, widest * 0.6, -widest * 0.8)
    scene.add(fill)

    // ---- geometry ---------------------------------------------------------

    const building = new THREE.Group()
    scene.add(building)

    // A unit cube scaled per floor, so every slab shares one geometry however
    // many different floor sizes the building has.
    const unitBox = new THREE.BoxGeometry(1, 1, 1)
    const deskGeometry = new THREE.BoxGeometry(DESK_WIDTH, DESK_HEIGHT, DESK_DEPTH)

    const slabMaterial = new THREE.MeshStandardMaterial({
        color: colours.slab,
        roughness: 0.85,
        metalness: 0.02,
        transparent: true,
        opacity: 1,
    })

    const deskColour = new THREE.Color(colours.desk)
    const hoverColour = new THREE.Color(colours.deskHover)

    const levels = floors.map((f) => f.level)
    const baseLevel = levels.length ? Math.min(...levels) : 0

    const dummy = new THREE.Object3D()

    const storeys = floors.map((floor) => {
        const group = new THREE.Group()
        group.userData = { floor, kind: 'floor' }

        const slab = new THREE.Mesh(unitBox, slabMaterial.clone())
        slab.scale.set(floor.width, SLAB_THICKNESS, floor.depth)
        slab.receiveShadow = true
        slab.castShadow = true
        slab.userData = { kind: 'floor', floor }
        group.add(slab)

        const outline = new THREE.LineSegments(
            new THREE.EdgesGeometry(unitBox),
            new THREE.LineBasicMaterial({ color: colours.slabEdge, transparent: true, opacity: 0.9 }),
        )
        outline.scale.copy(slab.scale)
        group.add(outline)

        // One InstancedMesh for every desk on the floor. Raycasting against it
        // hands back an instanceId, which indexes straight into floor.desks —
        // so hovering one desk out of three hundred costs no more than one out
        // of six, and the whole floor is a single draw call.
        let desks = null

        if (floor.desks.length > 0) {
            desks = new THREE.InstancedMesh(
                deskGeometry,
                new THREE.MeshStandardMaterial({ roughness: 0.5, metalness: 0.05 }),
                floor.desks.length,
            )
            desks.castShadow = true
            desks.receiveShadow = true

            floor.desks.forEach((desk, index) => {
                dummy.position.set(
                    (desk.x / 100 - 0.5) * floor.width,
                    SLAB_THICKNESS / 2 + DESK_HEIGHT / 2,
                    (desk.y / 100 - 0.5) * floor.depth,
                )
                dummy.updateMatrix()
                desks.setMatrixAt(index, dummy.matrix)
                desks.setColorAt(index, deskColour)
            })

            desks.instanceMatrix.needsUpdate = true
            group.add(desks)
        }

        const label = makeLabel(floor.name, colours.label)
        const sprite = sizeLabel(label, widest)
        // Off the right-hand edge of this floor's own slab, so the names line
        // up down the side of the building even when floors differ in size.
        sprite.position.set(floor.width / 2 + sprite.scale.x / 2 + 0.8, 0.4, 0)
        group.add(sprite)

        building.add(group)

        return { floor, group, slab, outline, desks, label: sprite, storey: floor.level - baseLevel }
    })

    const slabMeshes = storeys.map((s) => s.slab)

    // ---- layout -----------------------------------------------------------

    // A 60 m floor plate with 3.6 m between storeys is, honestly, a stack of
    // paper. The default spread pulls the floors apart enough to see into,
    // scaled to the building rather than fixed, and the slider still returns to
    // 1 for the true proportions.
    let explode = Math.min(4, Math.max(1, widest / 18))
    let focused = null

    if (explodeInput) {
        explodeInput.max = String(Math.max(4, Math.ceil(explode) + 1))
        explodeInput.value = String(explode)
    }

    function layout() {
        storeys.forEach((s) => {
            s.group.position.y = s.storey * STOREY_HEIGHT * explode
        })
    }

    /**
     * Focusing fades the other floors rather than hiding them, so the floor
     * you picked stays in the building instead of floating on its own.
     */
    function applyFocus() {
        storeys.forEach((s) => {
            const dimmed = focused !== null && s.floor.id !== focused
            s.slab.material.opacity = dimmed ? 0.12 : 1
            s.slab.material.transparent = dimmed
            s.slab.material.depthWrite = !dimmed
            s.outline.material.opacity = dimmed ? 0.12 : 0.9
            // The dimmed floors keep their names — that is how you know what
            // you are looking past, and what you would click to switch to.
            s.label.material.opacity = dimmed ? 0.3 : 1
            if (s.desks) s.desks.visible = !dimmed
        })

        floorButtons.forEach((button) => {
            button.dataset.active = String(Number(button.dataset.floorButton) === focused)
        })

        const floor = storeys.find((s) => s.floor.id === focused)?.floor
        if (focusLabel) focusLabel.textContent = floor ? floor.name : ''
        clearFocusButton?.toggleAttribute('hidden', !floor)
    }

    function focusFloor(id) {
        focused = focused === id ? null : id
        applyFocus()

        const storey = storeys.find((s) => s.floor.id === focused)

        if (!storey) {
            frameBuilding()

            return
        }

        frame(
            storey.group.position.y + 0.5,
            Math.hypot(storey.floor.width, storey.floor.depth) / 2 + 2,
        )
    }

    // ---- camera -----------------------------------------------------------

    let flight = null

    function flyTo(target, position, duration = 650) {
        flight = {
            fromTarget: controls.target.clone(),
            toTarget: target,
            fromPosition: camera.position.clone(),
            toPosition: position,
            start: performance.now(),
            duration,
        }
    }

    function buildingCentreY() {
        const top = storeys.length ? storeys[storeys.length - 1].group.position.y : 0

        return top / 2
    }

    /**
     * Puts the camera where a sphere of `radius` centred at `centreY` fits in
     * frame, on a fixed three-quarter view.
     *
     * Hand-tuned camera offsets looked right for one building and cropped the
     * moment the floors changed size. The distance comes off the field of view
     * instead, so the framing holds for any floor plate and any focus.
     */
    function frame(centreY, radius, { instant = false } = {}) {
        const vFov = THREE.MathUtils.degToRad(camera.fov)
        // Vertical FOV is the narrower of the two on a landscape canvas, so
        // fitting to it fits the width as well. 1.12 is breathing room.
        const distance = (radius * 1.12) / Math.sin(vFov / 2)

        const azimuth = THREE.MathUtils.degToRad(38)
        const elevation = THREE.MathUtils.degToRad(30)

        const target = new THREE.Vector3(0, centreY, 0)
        const position = new THREE.Vector3(
            distance * Math.cos(elevation) * Math.sin(azimuth),
            centreY + distance * Math.sin(elevation),
            distance * Math.cos(elevation) * Math.cos(azimuth),
        )

        // The first frame has nowhere to fly from — the camera is still at the
        // origin, and animating out of the middle of the building looks like a
        // fault rather than an introduction.
        if (instant) {
            camera.position.copy(position)
            controls.target.copy(target)
            flight = null

            return
        }

        flyTo(target, position)
    }

    function buildingRadius() {
        const height = storeys.length ? storeys[storeys.length - 1].group.position.y : 0
        const plate = Math.max(...floors.map((f) => Math.hypot(f.width, f.depth)), 12) / 2

        return Math.hypot(plate, height / 2) + 2
    }

    function frameBuilding(options = {}) {
        frame(buildingCentreY(), buildingRadius(), options)
    }

    function stepFlight(now) {
        if (!flight) return

        const t = Math.min(1, (now - flight.start) / flight.duration)
        // Ease-out cubic: quick to leave, gentle to arrive.
        const e = 1 - Math.pow(1 - t, 3)

        camera.position.lerpVectors(flight.fromPosition, flight.toPosition, e)
        controls.target.lerpVectors(flight.fromTarget, flight.toTarget, e)

        if (t === 1) flight = null
    }

    // ---- picking ----------------------------------------------------------

    const raycaster = new THREE.Raycaster()
    const pointer = new THREE.Vector2()
    let hovered = null
    let pointerInside = false

    function updatePointer(event) {
        const rect = renderer.domElement.getBoundingClientRect()
        pointer.x = ((event.clientX - rect.left) / rect.width) * 2 - 1
        pointer.y = -((event.clientY - rect.top) / rect.height) * 2 + 1
        pointerInside = true

        if (tooltip) {
            tooltip.style.left = `${event.clientX - rect.left}px`
            tooltip.style.top = `${event.clientY - rect.top}px`
        }
    }

    function pick() {
        if (!pointerInside) return null

        raycaster.setFromCamera(pointer, camera)

        const deskMeshes = storeys.map((s) => s.desks).filter((m) => m && m.visible)
        const deskHit = raycaster.intersectObjects(deskMeshes, false)[0]

        if (deskHit && deskHit.instanceId !== undefined) {
            const storey = storeys.find((s) => s.desks === deskHit.object)

            return {
                storey,
                floor: storey.floor,
                desk: storey.floor.desks[deskHit.instanceId],
                instanceId: deskHit.instanceId,
            }
        }

        const slabHit = raycaster.intersectObjects(slabMeshes, false)[0]

        if (!slabHit) return null

        const storey = storeys.find((s) => s.slab === slabHit.object)

        return { storey, floor: storey.floor, desk: null, instanceId: null }
    }

    /** Recolours a single instance — the whole point of not using 300 meshes. */
    function paintInstance(storey, instanceId, colour) {
        if (!storey?.desks || instanceId === null || instanceId === undefined) return

        storey.desks.setColorAt(instanceId, colour)
        storey.desks.instanceColor.needsUpdate = true
    }

    function setHovered(hit) {
        const same = hovered
            && hit
            && hovered.storey === hit.storey
            && hovered.instanceId === hit.instanceId

        if (same) return

        if (hovered) paintInstance(hovered.storey, hovered.instanceId, deskColour)

        hovered = hit

        if (hovered) paintInstance(hovered.storey, hovered.instanceId, hoverColour)

        if (!tooltip) return

        if (!hovered) {
            tooltip.hidden = true
            renderer.domElement.style.cursor = 'grab'

            return
        }

        tooltip.hidden = false
        tooltip.textContent = hovered.desk && focused === hovered.floor.id
            ? `${hovered.desk.name} · click for details`
            : (hovered.desk ? `${hovered.desk.name} · ${hovered.floor.name}` : hovered.floor.name)
        renderer.domElement.style.cursor = 'pointer'
    }

    renderer.domElement.addEventListener('pointermove', updatePointer)
    renderer.domElement.addEventListener('pointerleave', () => {
        pointerInside = false
        setHovered(null)
    })

    // A click that was really a drag should orbit, not select.
    let pressed = null
    renderer.domElement.addEventListener('pointerdown', (event) => {
        pressed = { x: event.clientX, y: event.clientY }
    })
    renderer.domElement.addEventListener('pointerup', (event) => {
        if (!pressed) return

        const moved = Math.hypot(event.clientX - pressed.x, event.clientY - pressed.y)
        pressed = null

        if (moved > 5) return

        updatePointer(event)
        const hit = pick()

        if (!hit) return

        // A desk on the floor you are already looking at opens its record;
        // anything else brings that floor forward first. Desks on the other
        // floors are hidden while one is focused, so this never fires for a
        // desk you cannot actually see.
        if (hit.desk && focused === hit.floor.id) {
            deskModal.open(hit.desk, hit.floor.name)

            return
        }

        focusFloor(hit.floor.id)
    })

    // ---- controls ---------------------------------------------------------

    explodeInput?.addEventListener('input', () => {
        explode = Number(explodeInput.value)
        layout()
    })

    resetButton?.addEventListener('click', () => {
        focused = null
        applyFocus()
        frameBuilding()
    })

    clearFocusButton?.addEventListener('click', () => {
        focused = null
        applyFocus()
        frameBuilding()
    })

    floorButtons.forEach((button) => {
        button.addEventListener('click', () => focusFloor(Number(button.dataset.floorButton)))
    })

    // ---- theme ------------------------------------------------------------

    function repaint() {
        colours = PALETTE[isDark() ? 'dark' : 'light']
        scene.background = new THREE.Color(colours.background)
        scene.fog.color = new THREE.Color(colours.background)
        deskColour.set(colours.desk)
        hoverColour.set(colours.deskHover)

        storeys.forEach((s) => {
            s.slab.material.color = new THREE.Color(colours.slab)
            s.outline.material.color = new THREE.Color(colours.slabEdge)

            if (s.desks) {
                for (let i = 0; i < s.floor.desks.length; i++) {
                    s.desks.setColorAt(i, deskColour)
                }

                s.desks.instanceColor.needsUpdate = true
            }

            // The label is pixels in a texture, so it cannot be recoloured —
            // it has to be redrawn.
            const replacement = sizeLabel(makeLabel(s.floor.name, colours.label), widest)
            replacement.position.copy(s.label.position)
            replacement.material.opacity = s.label.material.opacity
            s.group.remove(s.label)
            s.label.material.map.dispose()
            s.label.material.dispose()
            s.group.add(replacement)
            s.label = replacement
        })

        if (hovered) paintInstance(hovered.storey, hovered.instanceId, hoverColour)
    }

    new MutationObserver(repaint).observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class'],
    })

    // ---- loop -------------------------------------------------------------

    function resize() {
        const { clientWidth: w, clientHeight: h } = canvasHost

        if (!w || !h) return

        camera.aspect = w / h
        camera.updateProjectionMatrix()
        renderer.setSize(w, h, false)
    }

    new ResizeObserver(resize).observe(canvasHost)

    let running = true
    new IntersectionObserver(([entry]) => {
        // Nobody is looking at it while it is scrolled away; stop burning a
        // GPU frame every 16ms for an offscreen canvas.
        running = entry.isIntersecting
        if (running) tick()
    }).observe(canvasHost)

    let animationFrame = null

    function tick() {
        if (!running) {
            animationFrame = null

            return
        }

        animationFrame = requestAnimationFrame(tick)
        stepFlight(performance.now())
        controls.update()
        if (!flight) setHovered(pick())
        renderer.render(scene, camera)
    }

    layout()
    resize()
    applyFocus()
    frameBuilding({ instant: true })
    tick()

    root.dataset.ready = 'true'

    return {
        focusFloor,
        destroy() {
            running = false
            if (animationFrame) cancelAnimationFrame(animationFrame)
            controls.dispose()
            renderer.dispose()
        },
    }
}

function supportsWebGl() {
    try {
        const canvas = document.createElement('canvas')

        return !!(window.WebGLRenderingContext && (canvas.getContext('webgl2') || canvas.getContext('webgl')))
    } catch {
        return false
    }
}

// ---- boot -------------------------------------------------------------------

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-building]').forEach((root) => {
        const payload = root.querySelector('[data-scene]')?.textContent

        if (!payload) return

        mountBuilding(root, JSON.parse(payload))
    })
})
