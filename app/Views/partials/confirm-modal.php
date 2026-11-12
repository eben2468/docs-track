<?php
/**
 * Shared confirmation dialog. Any form with data-confirm="Question?" opens it before submitting.
 * Optional: data-confirm-button="Deactivate" and data-confirm-variant="danger|primary|warning".
 */
?>
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="confirmModalTitle">Please confirm</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" data-confirm-message></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" data-confirm-accept>Confirm</button>
            </div>
        </div>
    </div>
</div>
