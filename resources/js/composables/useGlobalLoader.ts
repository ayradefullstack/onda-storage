import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const isLoading = ref(false);
const loadingMessage = ref('');
const progress = ref(0);
let progressTimer: ReturnType<typeof setInterval> | null = null;
let safetyTimeout: ReturnType<typeof setTimeout> | null = null;

export function useGlobalLoader() {
    const startLoading = (msg = '') => {
        isLoading.value = true;
        loadingMessage.value = msg;
        progress.value = 15;

        if (typeof document !== 'undefined' && document.body) {
            document.body.style.overflow = 'hidden';
        }

        if (progressTimer) {
            clearInterval(progressTimer);
        }

        progressTimer = setInterval(() => {
            if (progress.value < 88) {
                progress.value += Math.floor(Math.random() * 8) + 4;
            }
        }, 100);

        // Safety fallback: auto-hide after 15 seconds in case of unhandled network drop
        if (safetyTimeout) {
            clearTimeout(safetyTimeout);
        }

        safetyTimeout = setTimeout(() => {
            if (isLoading.value) {
                stopLoading();
            }
        }, 15000);
    };

    const stopLoading = () => {
        progress.value = 100;

        if (progressTimer) {
            clearInterval(progressTimer);
            progressTimer = null;
        }

        if (safetyTimeout) {
            clearTimeout(safetyTimeout);
            safetyTimeout = null;
        }

        setTimeout(() => {
            isLoading.value = false;
            loadingMessage.value = '';
            progress.value = 0;

            if (typeof document !== 'undefined' && document.body) {
                document.body.style.overflow = '';
            }
        }, 250);
    };

    return {
        isLoading,
        loadingMessage,
        progress,
        startLoading,
        stopLoading,
    };
}

let isInitialized = false;

export function initializeGlobalLoader() {
    if (isInitialized) {
        return;
    }

    isInitialized = true;

    const { startLoading, stopLoading } = useGlobalLoader();

    // Trigger on any Inertia request start (form submit, visit, pagination, etc.)
    router.on('start', () => {
        // Optional: Can detect specific form actions or general navigation
        startLoading();
    });

    // Trigger on request completion
    router.on('finish', () => {
        stopLoading();
    });

    // Sync upload / download progress if available
    router.on('progress', (event) => {
        if (event.detail.progress?.percentage) {
            progress.value = Math.max(
                progress.value,
                event.detail.progress.percentage,
            );
        }
    });

    router.on('cancel', () => {
        stopLoading();
    });
}
