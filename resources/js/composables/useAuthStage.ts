import { ref } from 'vue';

/**
 * What the brand stage's vault scene shows. Module-level on purpose: the
 * stage lives in AuthLayout, which Inertia keeps mounted across Login ↔
 * Register, while the pages that know "a request is running" are children.
 */
export type StageState = 'idle' | 'busy' | 'stamped';

const state = ref<StageState>('idle');

export function useAuthStage() {
    return {
        stageState: state,
        setStageState(next: StageState) {
            state.value = next;
        },
    };
}
