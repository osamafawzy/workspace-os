{{--
    The workstation modal, shared by the flat floor plan and the 3D building.

    A native <dialog>: Escape closes it, the backdrop comes free, focus is
    trapped and returned to whatever opened it, and none of that is code we
    have to write or keep right. The body is filled in by desk-modal.js from
    the desk payload on the page.
--}}
<dialog data-desk-modal class="ws-modal" aria-labelledby="desk-modal-title">
    <div class="ws-modal__head">
        <div>
            <p class="ws-modal__eyebrow" data-desk-floor></p>
            <h2 class="ws-modal__title" id="desk-modal-title" data-desk-title></h2>
        </div>

        <button type="button" class="ws-modal__close" data-desk-close aria-label="Close">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M6 6l12 12M18 6 6 18" stroke-linecap="round"/>
            </svg>
        </button>
    </div>

    <div class="ws-modal__body" data-desk-body></div>

    <p class="ws-modal__foot" data-desk-position></p>
</dialog>
