<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class JournalEntryPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('journal_entries.view');
    }

    public function view(User $user, JournalEntry $entry): bool
    {
        return $user->can('journal_entries.view');
    }

    public function create(User $user): bool
    {
        return $user->can('journal_entries.create');
    }

    /**
     * Only draft entries can be edited — posted entries are immutable.
     */
    public function update(User $user, JournalEntry $entry): bool
    {
        return $user->can('journal_entries.edit') && $entry->isDraft();
    }

    /**
     * Only draft entries can be deleted.
     */
    public function delete(User $user, JournalEntry $entry): bool
    {
        return $user->can('journal_entries.delete') && $entry->isDraft();
    }

    /**
     * Post (ترحيل) a draft journal entry.
     */
    public function post(User $user, JournalEntry $entry): bool
    {
        return $user->can('journal_entries.post') && $entry->isDraft();
    }
}
