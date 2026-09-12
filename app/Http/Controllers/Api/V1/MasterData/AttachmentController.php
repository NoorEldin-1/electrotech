<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Api\AttachmentOwner;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\MasterData\StoreAttachmentRequest;
use App\Http\Resources\Api\V1\MasterData\AttachmentResource;
use App\Models\Attachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group 10. Attachments
 *
 * Documents attached to a record: the drawings and BOQ on a project, the
 * commercial registry and tax card on a supplier, the scanned purchase order,
 * the goods-receipt note on an addition voucher.
 *
 * One endpoint serves all of them. The caller names the owner with
 * `owner_type` + `owner_id`, where `owner_type` is one of a fixed set of keys
 * (`project`, `customer`, `supplier`, `purchase_order`, `addition_voucher`) —
 * never a PHP class name. See App\Http\Api\AttachmentOwner for why that
 * distinction is load-bearing.
 *
 * **Authorization has no permissions of its own.** Attaching a file to a
 * supplier is an edit of that supplier and reading one is a view of it, so the
 * owner's existing policy answers both. That is deliberate: a separate
 * `attachments.*` permission would start out matching the panel and drift the
 * first time somebody changed one and not the other.
 */
class AttachmentController extends ApiController
{
    /**
     * The disk the panel already writes attachments to. Kept in one constant
     * so the upload, the download and any future move agree by construction.
     */
    private const DISK = 'public';

    /**
     * List a record's attachments
     *
     * Not paginated: attachments belong to one record and are counted in
     * handfuls, so a page cursor would be ceremony over a list of four files.
     *
     * @authenticated
     *
     * @queryParam owner_type string required One of: project, customer, supplier, purchase_order, addition_voucher. Example: supplier
     * @queryParam owner_id integer required The owner record id. Example: 3
     * @queryParam category string optional Filter to one attachment category. Example: tax_card
     *
     * @response 200 scenario="Success" {"data":[{"id":12,"type":"attachment","file_name":"tax-card.pdf","file_type":"application/pdf","file_size":184320,"category":{"value":"tax_card","label":"Tax Card","color":null},"owner":{"type":"supplier","id":3},"download_url":"https://app.electrotech.findosystem.com/api/v1/attachments/12/download"}],"meta":{"request_id":"9f1c","api_version":"1","count":1}}
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'owner_type' => ['required', 'string'],
            'owner_id' => ['required', 'integer'],
            'category' => ['nullable', 'string'],
        ]);

        $owner = AttachmentOwner::resolve(
            (string) $request->query('owner_type'),
            (string) $request->query('owner_id'),
        );

        // Reading a record's documents is reading the record.
        $this->authorize('view', $owner);

        $query = AttachmentOwner::relation($owner)->with('uploadedBy:id,name');

        if (($category = $request->query('category')) !== null && $category !== '') {
            $query->where('category', $category);
        }

        return $this->respondCollection(
            AttachmentResource::collection($query->latest('id')->get())->resolve($request),
        );
    }

    /**
     * Upload an attachment
     *
     * Send as `multipart/form-data` with the binary in `file`. The
     * `Idempotency-Key` header is required as on every write, which is what
     * stops a retry on a weak connection from storing the same photo twice.
     *
     * @authenticated
     *
     * @bodyParam owner_type string required One of: project, customer, supplier, purchase_order, addition_voucher. Example: supplier
     * @bodyParam owner_id integer required The owner record id. Example: 3
     * @bodyParam category string required One of the attachment_category enum values. Example: tax_card
     * @bodyParam file file required The document. Max 20 MB; pdf, images, and Office documents only.
     *
     * @response 201 scenario="Created" {"data":{"id":12,"type":"attachment","file_name":"tax-card.pdf","file_size":184320,"owner":{"type":"supplier","id":3}},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function store(StoreAttachmentRequest $request): JsonResponse
    {
        $owner = AttachmentOwner::resolve(
            $request->string('owner_type')->toString(),
            $request->string('owner_id')->toString(),
        );

        // Adding a document to a record changes that record, so the gate is
        // the owner's update policy — not its view policy.
        $this->authorize('update', $owner);

        $ownerKey = $request->string('owner_type')->toString();
        $category = $request->string('category')->toString();
        $file = $request->file('file');

        // Same folder layout the Filament pages use, so a file uploaded from
        // the phone lands where the panel already looks for it.
        $directory = sprintf(
            '%s/%s/%s',
            AttachmentOwner::storagePrefix($ownerKey),
            $owner->getKey(),
            $category,
        );

        // The stored name is generated, never the client's. A filename is
        // attacker-controlled text that ends up as a path segment; letting it
        // through invites traversal and collisions between two people
        // uploading "scan.pdf" on the same day. The original is kept in
        // `file_name` for display only.
        $storedName = Str::uuid()->toString().'.'.strtolower((string) $file->getClientOriginalExtension());

        $path = $file->storeAs($directory, $storedName, self::DISK);

        $attachment = AttachmentOwner::relation($owner)->create([
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'category' => $category,
            'uploaded_by' => Auth::id(),
        ]);

        return $this->respondCreated(new AttachmentResource($attachment->load('uploadedBy:id,name')));
    }

    /**
     * Download an attachment
     *
     * Streams the bytes through this controller after checking the owner's
     * view policy. The raw storage path is never published: the panel keeps
     * these files on the public disk, so handing out the direct URL would make
     * every contract readable by anyone who guessed the path, with no
     * permission check anywhere in the way.
     *
     * The response is a file body, not the JSON envelope.
     *
     * @authenticated
     *
     * @urlParam attachment integer required The attachment id. Example: 12
     *
     * @response 200 scenario="Success" "<binary file contents>"
     * @response 404 scenario="File missing from disk" {"error":{"code":"not_found","message":"The requested resource was not found."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function download(Attachment $attachment): StreamedResponse
    {
        $this->authorizeOwnerOf($attachment, 'view');

        $disk = Storage::disk(self::DISK);

        // A row whose file vanished (a manual cleanup, a restore from a
        // partial backup) is a 404 rather than a 500: the record exists, the
        // bytes do not, and the client should treat it as a missing document.
        abort_unless($disk->exists($attachment->file_path), 404);

        return $disk->download($attachment->file_path, $attachment->file_name);
    }

    /**
     * Delete an attachment
     *
     * Removes the row and the file from disk. Gated by the owner's update
     * policy — deleting a supplier's tax card is an edit of that supplier.
     *
     * @authenticated
     *
     * @urlParam attachment integer required The attachment id. Example: 12
     *
     * @response 204 scenario="Deleted" {}
     */
    public function destroy(Attachment $attachment): JsonResponse
    {
        $this->authorizeOwnerOf($attachment, 'update');

        Storage::disk(self::DISK)->delete($attachment->file_path);

        $attachment->delete();

        return $this->respondNoContent();
    }

    /**
     * Run the given policy ability against the attachment's owner.
     *
     * An attachment whose owner has been hard-deleted, or whose
     * `attachable_type` is not in the whitelist, has nothing left to authorize
     * against. Refusing it is the only safe answer: falling back to "allow"
     * would make an orphan row readable by anyone with a token.
     */
    private function authorizeOwnerOf(Attachment $attachment, string $ability): void
    {
        $key = AttachmentOwner::keyFor($attachment);

        abort_if($key === null, 404);

        $owner = $key === 'project'
            ? $attachment->project
            : $attachment->attachable;

        abort_if($owner === null, 404);

        $this->authorize($ability, $owner);
    }
}
