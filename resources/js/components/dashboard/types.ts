import type { LabelSource } from '@/components/oeuvre/label';

/**
 * The author dashboard's props, as `Author\DashboardController` sends them.
 * Every value comes from the server, scoped to the signed-in author; the
 * page computes nothing beyond formatting.
 */

/** One count per status, plus `all` and the `pending` filter's total. */
export type DashboardCounts = Record<string, number>;

export interface GateReason {
    code: string;
    params: Record<string, string | number | null>;
}

export interface DashboardAction {
    uuid: string;
    label_source: LabelSource;
    status: string;
    /** `ready` only when SubmissionGate passes and the policy allows it. */
    kind: 'rejected' | 'incomplete' | 'ready';
    blockers: GateReason[];
    rejection: { reason: string | null; at: string | null } | null;
}

export interface DashboardActions {
    items: DashboardAction[];
    /** Every draft or rejected deposit, including the ones not listed. */
    total: number;
}

export interface DashboardRecent {
    uuid: string;
    label_source: LabelSource;
    status: string;
    last_activity_at: string | null;
}

/** Null when the author has no quota row (no limit is enforced yet). */
export interface DashboardQuota {
    used_bytes: number;
    reserved_bytes: number;
    limit_bytes: number;
}

export interface DashboardDecision {
    uuid: string;
    oeuvre_uuid: string;
    label_source: LabelSource;
    to_status: 'under_review' | 'registered' | 'rejected';
    at: string | null;
}
