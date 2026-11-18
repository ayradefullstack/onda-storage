<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;

/**
 * The author-facing decision notifications are queued on `default`, never
 * written inside the officer's request: a decision must not wait on, or
 * fail because of, telling someone about it. `default` and not `media`,
 * so a 5 GB hash cannot delay a bell (see CLAUDE.md, Queue).
 *
 * The classes using this also implement ShouldQueue; the trait only says
 * where and how.
 */
trait QueuedForAuthor
{
    use Queueable;

    /**
     * A deposit deleted between the decision and the worker picking the
     * job up has nobody left to tell — drop it instead of failing.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['database' => 'default'];
    }
}
