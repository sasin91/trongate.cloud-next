// Scale the 1440px-wide dashboard shot to fit its frame.
(() => {
    const frame = document.querySelector('.lp-frame-inner');
    if (!frame || !('ResizeObserver' in window)) {
        return;
    }
    new ResizeObserver(([entry]) => {
        frame.style.setProperty('--shot-scale', String(entry.contentRect.width / 1440));
    }).observe(frame);
})();
