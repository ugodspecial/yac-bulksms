/**
 * Small dependency-free toast helper used by Livewire components.
 * Components dispatch: $this->dispatch('notify', message: '...', type: 'success').
 */
const TONE = {
    success: 'border-emerald-200 bg-emerald-50 text-emerald-900',
    error: 'border-rose-200 bg-rose-50 text-rose-900',
    warning: 'border-amber-200 bg-amber-50 text-amber-900',
    info: 'border-slate-200 bg-white text-slate-900',
};

function ensureContainer() {
    let container = document.getElementById('toast-container');

    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'pointer-events-none fixed inset-x-0 bottom-4 z-[100] flex flex-col items-center gap-2 px-4 sm:items-end sm:px-6';
        document.body.appendChild(container);
    }

    return container;
}

export function showToast(message, type = 'info') {
    if (!message) {
        return;
    }

    const container = ensureContainer();
    const toast = document.createElement('div');
    toast.className = `pointer-events-auto w-full max-w-md rounded-xl border px-4 py-3 text-sm shadow-lg transition duration-200 ${TONE[type] || TONE.info}`;
    toast.setAttribute('role', 'status');
    toast.textContent = message;
    container.appendChild(toast);

    setTimeout(() => {
        toast.classList.add('opacity-0', 'translate-y-2');
        setTimeout(() => toast.remove(), 250);
    }, 4500);
}

window.yacToast = showToast;

document.addEventListener('livewire:init', () => {
    window.Livewire.on('notify', (payload) => {
        const data = Array.isArray(payload) ? payload[0] : payload;
        showToast(data?.message, data?.type || 'info');
    });
});
