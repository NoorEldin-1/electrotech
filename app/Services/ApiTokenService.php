<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Issuing, rotating and revoking API access tokens.
 *
 * Lives in App\Services with the rest of the business logic, following the
 * project rule that controllers only validate, authorize, delegate and
 * serialize. Both the API controllers and any future console command
 * ("issue a token for the warehouse tablet") go through here.
 */
class ApiTokenService
{
    /**
     * Mint a token for a user's device.
     *
     * @param  list<string>  $abilities  Requested scopes; `['*']` = the user's
     *                                   full rights. Anything outside the
     *                                   catalog in config('api.abilities') is
     *                                   rejected rather than silently dropped,
     *                                   so a client typo surfaces immediately
     *                                   instead of producing a token that
     *                                   quietly cannot do its job.
     */
    public function issue(User $user, string $deviceName, array $abilities = ['*']): NewAccessToken
    {
        $abilities = $this->validateAbilities($abilities);

        return DB::transaction(function () use ($user, $deviceName, $abilities): NewAccessToken {
            $this->enforceTokenLimit($user);

            return $user->createToken($deviceName, $abilities, $this->expiresAt());
        });
    }

    /**
     * Rotate the caller's current token: issue a replacement, revoke the old
     * one, atomically.
     *
     * Rotation rather than "extend the expiry" because a stolen token then has
     * a bounded life even if the thief keeps using it — the legitimate client
     * rotating will not extend the attacker's copy, and the user sees a device
     * entry they do not recognise.
     *
     * The new token inherits the old one's name and abilities: refresh must
     * never be a privilege-escalation path.
     */
    public function refresh(User $user, PersonalAccessToken $current): NewAccessToken
    {
        return DB::transaction(function () use ($user, $current): NewAccessToken {
            $fresh = $user->createToken(
                $current->name,
                $current->abilities ?? ['*'],
                $this->expiresAt(),
            );

            $current->delete();

            return $fresh;
        });
    }

    /**
     * Revoke one specific device session.
     *
     * Scoped to the owning user by the caller-supplied $user, so a token id
     * guessed from another account cannot be revoked — a trivial but real
     * denial-of-service otherwise.
     */
    public function revokeDevice(User $user, int $tokenId): void
    {
        $token = $user->tokens()->whereKey($tokenId)->first();

        if ($token === null) {
            throw new DomainException(__('errors.api.model_not_found', ['model' => 'device session']));
        }

        $token->delete();
    }

    /**
     * Sign out everywhere. Used by "log out all devices" and — mandatorily —
     * after a password change: a changed password that leaves old tokens alive
     * gives the user a false sense that they have locked an intruder out.
     */
    public function revokeAll(User $user): int
    {
        return $user->tokens()->delete();
    }

    /**
     * Sign out every device except the one making the request. This is what a
     * password change uses, so the user is not logged out of the app they are
     * currently holding.
     */
    public function revokeAllExceptCurrent(User $user, ?PersonalAccessToken $current): int
    {
        $query = $user->tokens();

        if ($current !== null) {
            $query->whereKeyNot($current->getKey());
        }

        return $query->delete();
    }

    /**
     * The user's live sessions, newest first. Expired rows are filtered out so
     * the list matches what actually still works — the nightly
     * `sanctum:prune-expired` job removes them for good.
     *
     * @return \Illuminate\Support\Collection<int, PersonalAccessToken>
     */
    public function activeDevices(User $user): \Illuminate\Support\Collection
    {
        return $user->tokens()
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * @param  list<string>  $abilities
     * @return list<string>
     */
    private function validateAbilities(array $abilities): array
    {
        if ($abilities === [] || $abilities === ['*']) {
            return ['*'];
        }

        $catalog = (array) config('api.abilities', []);
        $unknown = array_diff($abilities, $catalog);

        if ($unknown !== []) {
            throw new DomainException(sprintf(
                'Unknown token ability/abilities: %s. Allowed: %s.',
                implode(', ', $unknown),
                implode(', ', $catalog),
            ));
        }

        return array_values(array_unique($abilities));
    }

    /**
     * A user holding an unbounded number of live tokens is a slow leak: every
     * reinstall of the app leaves another valid credential behind.
     *
     * When the cap is reached we evict the least-recently-used token rather
     * than refusing the login. Refusing would mean a user locked out of their
     * own account by tablets they no longer own — a worse failure than
     * silently dropping the stalest session.
     */
    private function enforceTokenLimit(User $user): void
    {
        $max = (int) config('api.tokens.max_per_user');

        if ($max < 1) {
            return;
        }

        $live = $user->tokens()->count();

        if ($live < $max) {
            return;
        }

        // `last_used_at` is null for a token that was issued but never used;
        // those sort first and are evicted first, which is the right call.
        $user->tokens()
            ->orderByRaw('last_used_at IS NULL DESC')
            ->orderBy('last_used_at')
            ->orderBy('created_at')
            ->limit($live - $max + 1)
            ->get()
            ->each->delete();
    }

    /**
     * Null means "never expires" — only when the TTL is configured to 0, which
     * we do not do in production. See config/api.php for why 30 days.
     */
    private function expiresAt(): ?Carbon
    {
        $minutes = (int) config('api.tokens.ttl_minutes');

        return $minutes > 0 ? now()->addMinutes($minutes) : null;
    }
}
