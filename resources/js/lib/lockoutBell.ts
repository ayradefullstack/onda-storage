/**
 * An optional, synthesized "you can try again" bell (Web Audio, no asset).
 *
 * Browsers only allow audio after a user gesture, so `arm()` must be called
 * synchronously from the user's input event: the AudioContext is created
 * before any `await`, then resumed. Nothing here ever throws.
 */
export type BellArmResult = 'ready' | 'blocked' | 'unsupported';

export type LockoutBell = {
    readonly supported: boolean;
    arm(): Promise<BellArmResult>;
    /** false when there is no usable context or the browser keeps it muted. */
    ring(): Promise<boolean>;
    disarm(): Promise<void>;
};

type AudioContextFactory = () => AudioContext | null;

type AudioGlobals = {
    AudioContext?: typeof AudioContext;
    webkitAudioContext?: typeof AudioContext;
};

function audioConstructor(): typeof AudioContext | undefined {
    const g = globalThis as unknown as AudioGlobals;

    return g.AudioContext ?? g.webkitAudioContext;
}

const defaultFactory: AudioContextFactory = () => {
    const Ctor = audioConstructor();

    return Ctor ? new Ctor() : null;
};

const stateOf = (context: AudioContext): string => context.state;

function playBell(context: AudioContext): void {
    const t = context.currentTime;
    const gain = context.createGain();

    gain.gain.setValueAtTime(0.0001, t);
    gain.gain.exponentialRampToValueAtTime(0.2, t + 0.01);
    gain.gain.exponentialRampToValueAtTime(0.0001, t + 1.2);
    gain.connect(context.destination);

    for (const frequency of [880, 1320]) {
        const oscillator = context.createOscillator();

        oscillator.type = 'sine';
        oscillator.frequency.value = frequency;
        oscillator.connect(gain);
        oscillator.start(t);
        oscillator.stop(t + 1.3);
    }
}

export function createLockoutBell(factory?: AudioContextFactory): LockoutBell {
    let context: AudioContext | null = null;

    return {
        get supported() {
            return factory !== undefined || audioConstructor() !== undefined;
        },

        async arm() {
            if (context === null || stateOf(context) === 'closed') {
                try {
                    // Synchronous, before any await: keeps user activation.
                    context = (factory ?? defaultFactory)();
                } catch {
                    context = null;
                }
            }

            const current = context;

            if (current === null) {
                return 'unsupported';
            }

            try {
                await current.resume();
            } catch {
                return 'blocked';
            }

            return stateOf(current) === 'running' ? 'ready' : 'blocked';
        },

        async ring() {
            const current = context;

            if (current === null || stateOf(current) === 'closed') {
                return false;
            }

            try {
                await current.resume();

                if (stateOf(current) !== 'running') {
                    return false;
                }

                playBell(current);

                return true;
            } catch {
                return false;
            }
        },

        async disarm() {
            const current = context;

            context = null;

            if (current === null) {
                return;
            }

            try {
                await current.close();
            } catch {
                // Already closed or unsupported: nothing left to release.
            }
        },
    };
}
