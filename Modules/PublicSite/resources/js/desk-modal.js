/**
 * The workstation modal, shared by the flat floor plan and the 3D building.
 *
 * Everything shown here was grouped and labelled on the server, so this file
 * only lays out what it is handed. Adding a field to a workstation should not
 * mean editing JavaScript.
 *
 * The dialog is a native <dialog> opened with showModal(), which brings Escape,
 * the backdrop, the focus trap and returning focus to the opener with it — all
 * things worth not reimplementing.
 */

/** Text, never markup: desk names and notes are user input. */
function el(tag, className, text) {
    const node = document.createElement(tag)
    if (className) node.className = className
    if (text !== undefined && text !== null) node.textContent = text

    return node
}

function renderGroup(group) {
    const section = el('section', 'ws-modal__group')
    section.append(el('h3', 'ws-modal__group-title', group.title))

    // Notes run to a paragraph rather than fitting a label/value pair, so they
    // get the width instead of a column of their own.
    if (group.title === 'Notes') {
        const note = group.rows[0]?.value

        section.append(el('p', 'ws-modal__notes', note ?? '—'))

        return section
    }

    const list = el('dl', 'ws-modal__rows')

    group.rows.forEach((row) => {
        const pair = el('div', 'ws-modal__row')
        pair.append(el('dt', null, row.label))

        // A blank inside a group that has something is worth showing: it says
        // "not traced yet" rather than "no such field".
        const value = el('dd', row.mono ? 'ws-modal__value ws-modal__value--mono' : 'ws-modal__value', row.value ?? '—')
        value.dataset.empty = String(row.value === null || row.value === undefined)
        pair.append(value)
        list.append(pair)
    })

    section.append(list)

    return section
}

function placement(desk) {
    if (desk.x === null || desk.x === undefined || desk.y === null || desk.y === undefined) {
        return 'Not yet positioned on the floor plan.'
    }

    return `On the plan at ${desk.x}%, ${desk.y}%.`
}

/**
 * Wires up the dialog in the page and hands back an opener.
 *
 * Returns a no-op opener when the partial is not on the page, so a caller does
 * not have to guard every call site.
 */
export function mountDeskModal(root = document) {
    const dialog = root.querySelector('[data-desk-modal]')

    if (!dialog) return { open() {}, close() {} }

    const title = dialog.querySelector('[data-desk-title]')
    const floorName = dialog.querySelector('[data-desk-floor]')
    const body = dialog.querySelector('[data-desk-body]')
    const foot = dialog.querySelector('[data-desk-position]')

    dialog.querySelector('[data-desk-close]')?.addEventListener('click', () => dialog.close())

    // Clicking the backdrop closes it. The dialog element itself fills the
    // whole viewport as far as the event target is concerned, so a click is on
    // the backdrop exactly when it landed outside the dialog's own box.
    dialog.addEventListener('click', (event) => {
        if (event.target !== dialog) return

        const box = dialog.getBoundingClientRect()
        const inside = event.clientX >= box.left && event.clientX <= box.right
            && event.clientY >= box.top && event.clientY <= box.bottom

        if (!inside) dialog.close()
    })

    return {
        open(desk, floor = null) {
            if (!desk) return

            title.textContent = desk.name
            floorName.textContent = floor ?? desk.floor ?? ''
            floorName.hidden = !floorName.textContent
            foot.textContent = placement(desk)

            body.replaceChildren(
                ...(desk.groups?.length
                    ? desk.groups.map(renderGroup)
                    : [el('p', 'ws-modal__empty', 'Nothing has been recorded about this desk yet.')]),
            )

            if (!dialog.open) dialog.showModal()
        },
        close() {
            dialog.close()
        },
    }
}
