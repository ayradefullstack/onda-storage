<?php

// Officer-side review messages: conflicts, refusals, per-file decisions and
// the success toasts of the review flow.

return [
    // ReviewConflict.
    'held_by' => ':name is already reviewing this work. Take it over explicitly to decide on it.',
    'status_moved' => 'This work is now ":status". Reload before deciding on it.',
    'already_decided' => ':name has already decided on this work.',
    'not_under_review' => 'This work is not under review; open it for review before deciding on its files.',
    'reason_required' => 'A rejection must carry a reason for the author.',

    // Registration refusals.
    'approval_refused' => 'This work cannot be registered yet.',
    'files_not_accepted' => 'Every file must be accepted before this work can be registered.',
    'file_not_accepted' => ':name has not been accepted.',
    'file_with_decision' => ':name (:decision)',

    // Per-file decision refusals.
    'file_accept_needs_ready' => 'Only a ready file can be accepted; this file is ":status".',
    'file_decide_needs_finished' => 'This file is still ":status"; it can be decided once the pipeline has finished with it.',
    'file_removed' => 'This file was removed by the author; it can no longer be decided.',
    'file_foreign' => 'This file does not belong to this work.',

    // Success toasts.
    'toast_file_decision' => 'The file decision has been recorded.',
    'toast_registered' => 'The work has been registered.',
    'toast_rejected' => 'The author has been told why this work was rejected.',
];
