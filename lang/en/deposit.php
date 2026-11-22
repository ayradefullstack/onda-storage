<?php

// Author-side deposit messages: submission blockers, status-machine refusals,
// file removal and its conflicts, and the success toasts of those flows.

return [
    // OeuvreStatusMachine refusals (:from / :to are status labels).
    'illegal_between' => 'This work cannot move from ":from" to ":to".',
    'illegal_terminal' => 'This work is registered. A registered deposit is final and cannot move to ":to".',
    'illegal_actor' => 'You are not allowed to move this work from ":from" to ":to".',

    // SubmissionGate blockers and advisories.
    'submission_not_ready' => 'This work is not ready to be submitted.',
    'no_files' => 'This work has no files yet.',
    'file_in_pipeline' => '":name" is still being processed (:status). Wait for it to finish.',
    'file_failed' => '":name" failed to process. Remove it or upload it again.',
    'file_quarantined' => '":name" was quarantined by the malware scan. Remove it.',
    'file_rejected' => '":name" was rejected by the ONDA. Remove it and upload a compliant file.',
    'slot_empty' => 'The required document ":name" has no file yet.',
    'slot_empty_conditional' => 'The document ":name" is empty; it may not apply to this work.',

    // RemovalGate refusals.
    'removal_mid_pipeline' => '":name" is still being processed (:status). Wait for it to finish, then remove it.',
    'removal_still_uploading' => '":name" is still uploading. Cancel the upload instead of removing it.',

    // RemovalConflict (409): the author lost a race with an officer.
    'removal_no_longer_rejected' => 'This file is no longer rejected; it can no longer be removed.',
    'removal_not_removable' => 'This file has just been accepted by the ONDA; it can no longer be removed.',
    'removal_oeuvre_decided' => 'This work has been decided; its files can no longer be changed.',

    // Success toasts.
    'toast_submitted' => 'Your work has been submitted for review.',
    'toast_deleted' => 'The work has been deleted.',
    'toast_file_removed' => 'The file has been removed from this work.',
];
