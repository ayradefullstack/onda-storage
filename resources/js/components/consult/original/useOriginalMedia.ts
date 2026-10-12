import { ref } from 'vue';
import type { Ref } from 'vue';
import { classifyMediaFailure, createResumePoint } from './mediaPlayback';

/**
 * Shared by the video and audio viewers. An `error` event is classified
 * (expired URL vs. undecodable media); an expired URL asks the parent for
 * fresh descriptors and playback resumes at the remembered second once the
 * element reloads with the new URL. An undecodable file shows a card — there
 * is never a fall-back to a download.
 */
export function useOriginalMedia(
    player: Ref<HTMLMediaElement | null>,
    currentUrl: () => string,
    onExpired: () => void,
    fetchFn: typeof fetch = (...args) => fetch(...args),
) {
    const unreadable = ref(false);
    const resume = createResumePoint();

    async function onError(): Promise<void> {
        const position = player.value?.currentTime ?? 0;

        if ((await classifyMediaFailure(currentUrl(), fetchFn)) === 'expired') {
            resume.remember(position);
            onExpired();

            return;
        }

        unreadable.value = true;
    }

    function onLoadedMetadata(): void {
        const at = resume.take();

        if (at > 0 && player.value !== null) {
            player.value.currentTime = at;
            void player.value.play().catch(() => undefined);
        }
    }

    return { unreadable, onError, onLoadedMetadata };
}
