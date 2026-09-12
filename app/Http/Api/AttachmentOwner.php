<?php

declare(strict_types=1);

namespace App\Http\Api;

use App\Models\AdditionVoucher;
use App\Models\Attachment;
use App\Models\Customer;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Validation\ValidationException;

/**
 * The whitelist of things a file may be attached to.
 *
 * The attachments endpoint is generic — one upload route serves the project
 * file, the supplier's tax card and the scanned purchase order — so the client
 * has to name the owner. It names it with a *key* from this list, never with a
 * class name.
 *
 * That distinction is the whole point of the class. `attachable_type` is a
 * plain string column: if the request body could set it, a caller could attach
 * a row to `App\Models\User`, or to any model at all, and then read it back
 * through an endpoint whose authorization was written with suppliers in mind.
 * Mapping a closed set of keys onto a closed set of classes means an unknown
 * owner is a 422 before anything is written.
 *
 * Authorization is delegated, not reinvented: attaching a document to a
 * supplier is an edit of that supplier, and reading it is a view of that
 * supplier. So the owner's own policy answers both questions, and no
 * `attachments.*` permission exists to drift out of sync with the panel.
 */
final class AttachmentOwner
{
    /**
     * @var array<string, class-string<Model>>
     */
    private const TYPES = [
        'project' => Project::class,
        'customer' => Customer::class,
        'supplier' => Supplier::class,
        'purchase_order' => PurchaseOrder::class,
        'addition_voucher' => AdditionVoucher::class,
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * @return class-string<Model>|null
     */
    public static function classFor(string $key): ?string
    {
        return self::TYPES[$key] ?? null;
    }

    /**
     * Resolve `owner_type` + `owner_id` into the model, or fail validation.
     *
     * A missing record is a 422 on `owner_id` rather than a 404: the caller
     * addressed `/attachments`, which exists — it is the *body* that names
     * something that does not.
     */
    public static function resolve(string $key, int|string $id): Model
    {
        $class = self::classFor($key);

        if ($class === null) {
            throw ValidationException::withMessages([
                'owner_type' => [sprintf(
                    'Unknown owner_type "%s". Allowed: %s.',
                    $key,
                    implode(', ', self::keys()),
                )],
            ]);
        }

        $owner = $class::query()->find($id);

        if ($owner === null) {
            throw ValidationException::withMessages([
                'owner_id' => ["No {$key} exists with that id."],
            ]);
        }

        return $owner;
    }

    /**
     * The owner's attachments relation. Project uses a dedicated `project_id`
     * column (it predates the polymorphic ones and was deliberately left
     * alone); everything else uses the `attachable` morph. Both are relations
     * that `create()` fills in correctly, so callers need not care which.
     */
    public static function relation(Model $owner): HasMany|MorphMany
    {
        /** @var HasMany|MorphMany */
        return $owner->attachments();
    }

    /**
     * The public key for an attachment's owner, for serialization.
     */
    public static function keyFor(Attachment $attachment): ?string
    {
        if ($attachment->project_id !== null) {
            return 'project';
        }

        if ($attachment->attachable_type === null) {
            return null;
        }

        foreach (self::TYPES as $key => $class) {
            // Compared against the morph *class* rather than the raw class
            // name so a future entry in config/morph aliases keeps working.
            if ($attachment->attachable_type === (new $class)->getMorphClass()) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The storage directory prefix for an owner's files.
     *
     * These strings are not invented here — they are exactly the prefixes the
     * Filament pages already pass to EntityAttachmentPersistence. Getting one
     * wrong would not fail: it would quietly build a second folder tree, so
     * a file uploaded from the phone would sit somewhere the panel never
     * looks.
     */
    public static function storagePrefix(string $key): string
    {
        return match ($key) {
            'project' => 'attachments',
            'customer' => 'customer-attachments',
            'supplier' => 'supplier-attachments',
            'purchase_order' => 'po-attachments',
            'addition_voucher' => 'addition-voucher-attachments',
        };
    }
}
