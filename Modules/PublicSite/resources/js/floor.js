/**
 * The flat floor page: click a desk, read its record.
 *
 * Deliberately tiny and free of Three.js. This page is also what somebody
 * without WebGL gets, so it must not pull in the renderer it exists to replace.
 *
 * One listener on the page rather than one per desk: a floor plate can carry
 * three hundred pins and the same desk appears twice, once as a dot and once as
 * a row in the list.
 */
import { mountDeskModal } from './desk-modal.js'

const payload = document.querySelector('script[data-desks]')

if (payload) {
    const desks = new Map(JSON.parse(payload.textContent).map((desk) => [desk.id, desk]))
    const modal = mountDeskModal()

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-desk]')

        if (!trigger) return

        modal.open(desks.get(Number(trigger.dataset.desk)))
    })
}
