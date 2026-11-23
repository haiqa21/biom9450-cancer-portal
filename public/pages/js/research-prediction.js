// Prediction comparison: small improvements to the feedback form.
// Everything here is optional: the form works and is fully checked by
// feedback-save.php with JavaScript turned off.

document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('.feedback-form');
    if (!form) {
        return;   // "not available" page: no form
    }

    // --- Live character counter for the comment ----------------------------
    const comment = document.getElementById('comment');
    const counter = document.getElementById('comment-count');
    const max = Number(counter.dataset.max);
    const syncCount = () => {
        counter.querySelector('.num').textContent = comment.value.length;
        counter.classList.toggle('over', comment.value.length > max);
    };
    comment.addEventListener('input', syncCount);
    syncCount();

    // --- Quick check before sending ------------------------------------------
    // Stops a form with no rating and focuses the first rating option.
    // The server repeats the check, so this is only for speed.
    form.addEventListener('submit', (event) => {
        if (!form.querySelector('input[name="rating"]:checked')) {
            event.preventDefault();
            document.getElementById('rating').focus();
            return;
        }
        // Disable the button after the first click so a double click can't post twice
        form.querySelector('button[type="submit"]').disabled = true;
    });
});
