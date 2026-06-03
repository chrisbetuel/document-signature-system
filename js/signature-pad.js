// Minimal signature pad wiring

(function () {
  function initSignaturePad() {
    const canvas = document.getElementById('signature-pad');
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#111827';

    let drawing = false;
    let lastX = 0;
    let lastY = 0;

    function getPos(evt) {
      const rect = canvas.getBoundingClientRect();
      const scaleX = canvas.width / rect.width;
      const scaleY = canvas.height / rect.height;
      return {
        x: (evt.clientX - rect.left) * scaleX,
        y: (evt.clientY - rect.top) * scaleY
      };
    }

    function start(evt) {
      drawing = true;
      const p = getPos(evt);
      lastX = p.x;
      lastY = p.y;
      evt.preventDefault();
    }

    function move(evt) {
      if (!drawing) return;
      const p = getPos(evt);
      ctx.beginPath();
      ctx.moveTo(lastX, lastY);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      lastX = p.x;
      lastY = p.y;
      evt.preventDefault();
    }

    function end(evt) {
      drawing = false;
      evt.preventDefault();
    }

    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    window.addEventListener('mouseup', end);

    canvas.addEventListener('touchstart', start, { passive: false });
    canvas.addEventListener('touchmove', move, { passive: false });
    window.addEventListener('touchend', end, { passive: false });

    // Expose clear helper used by fill.js
    window.clearSignature = function () {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
    };
  }

  window.addEventListener('DOMContentLoaded', initSignaturePad);
})();

