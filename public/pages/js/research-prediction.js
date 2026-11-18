// Prediction comparison: small improvements to the feedback form.
// Everything here is optional: the form works and is fully checked by
// feedback-save.php with JavaScript turned off.

document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('.feedback-form');
    if (!form) {
        return;   // "not available" page: no form
    }

    // --- Data quality reason: only show it while the box is ticked ---------
    const flag = document.getElementById('quality_flag');
    const reasonField = document.getElementById('quality-reason-field');
    const syncReason = () => { reasonField.hidden = !flag.checked; };
    flag.addEventListener('change', syncReason);
    syncReason();

    // --- "Which type do you think it is?" hint when Disagree is picked ------
    const hint = document.getElementById('disagree-hint');
    const syncHint = () => {
        const picked = form.querySelector('input[name="agreement"]:checked');
        hint.hidden = !(picked && picked.value === 'disagree');
    };
    form.querySelectorAll('input[name="agreement"]').forEach((radio) => radio.addEventListener('change', syncHint));
    syncHint();

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

    // --- Quick checks before sending ----------------------------------------
    // Stops an obviously incomplete form and focuses the first problem.
    // The server repeats every check, so these are only for speed.
    form.addEventListener('submit', (event) => {
        const missing =
            (!form.querySelector('input[name="agreement"]:checked') && document.getElementById('agreement')) ||
            (!form.querySelector('input[name="usefulness"]:checked') && document.getElementById('usefulness')) ||
            (flag.checked && !document.getElementById('quality_reason').value && document.getElementById('quality_reason'));

        if (missing) {
            event.preventDefault();
            missing.focus();
            return;
        }

        // Disable the button after the first click so a double click can't post twice
        form.querySelector('button[type="submit"]').disabled = true;
    });
});
