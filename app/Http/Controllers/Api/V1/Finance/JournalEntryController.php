<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Finance;

use App\Enums\AccountDirection;
use App\Enums\DocumentType;
use App\Enums\JournalStatus;
use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Finance\ReplaceJournalEntryLinesRequest;
use App\Http\Requests\Api\V1\Finance\StoreJournalEntryRequest;
use App\Http\Requests\Api\V1\Finance\UpdateJournalEntryRequest;
use App\Http\Resources\Api\V1\Finance\JournalEntryResource;
use App\Models\JournalEntry;
use App\Services\JournalEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @group 30. Journal entries
 *
 * قيود اليومية — the general ledger's only source of movement.
 *
 * An entry is written as **two columns**, مدين and دائن, rather than as rows
 * each carrying a direction. The side a line is written on IS its direction,
 * which removes a whole class of mistake: a row cannot end up on the wrong
 * side because someone mistyped a field. The read shape gives you both — the
 * flat `lines` and the split `debit_lines` / `credit_lines`.
 *
 * Draft → **post**. Posting enforces double entry: at least two lines, and
 * total debits equal to total credits. A posted entry is then **immutable** —
 * no edit, no delete, no re-post — because its numbers have already reached
 * the trial balance and every statement built on it. The correction for a
 * mistake is a reversing entry.
 *
 * Numbering is threefold and all of it is the server's: `entry_serial` is the
 * continuous sequence an auditor follows, `document_number` the per-type
 * sequence (أمر صرف / إيصال توريد / قيد تسوية), and `entry_number` the
 * human-readable reference.
 */
class JournalEntryController extends ApiController
{
    public function __construct(
        private readonly JournalEntryService $journals,
    ) {}

    /**
     * List journal entries
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam search string Matches the entry number, document number or description. Example: JE-2026
     * @queryParam filter[status] string draft or posted. Example: posted
     * @queryParam filter[document_type] string payment_order, supply_receipt or settlement. Example: payment_order
     * @queryParam filter[entry_date] string Inclusive date range from,until. Example: 2026-01-01,2026-06-30
     * @queryParam filter[currency] string Example: EGP
     * @queryParam sort string Allowed: entry_serial, entry_number, entry_date, total_debit, created_at. Example: -entry_date
     *
     * @response 200 scenario="Success" {"data":[{"id":77,"type":"journal_entry","entry_number":"JE-202609-0031","entry_serial":412,"status":{"value":"posted","label":"Posted","color":"success"},"totals":{"debit":"40000.00","credit":"40000.00","balanced":true}}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', JournalEntry::class);

        $entries = ApiQuery::for(
            JournalEntry::query()
                ->withCount('lines')
                // The resource publishes each side's line total and whether the
                // entry balances. Reading those off the model would load the
                // lines once per row; two subqueries instead, which the
                // resource prefers when present (Finding #15).
                ->withSum(
                    ['lines as lines_debit_sum' => fn ($query) => $query->where('direction', AccountDirection::Debit->value)],
                    'amount',
                )
                ->withSum(
                    ['lines as lines_credit_sum' => fn ($query) => $query->where('direction', AccountDirection::Credit->value)],
                    'amount',
                ),
            $request,
        )
            ->allowFilters([
                'status' => ApiQuery::exact('status'),
                'document_type' => ApiQuery::exact('document_type'),
                'entry_date' => ApiQuery::dateBetween('entry_date'),
                'currency' => ApiQuery::exact('currency'),
            ])
            ->allowSearch(['entry_number', 'document_number', 'description'])
            ->allowSorts(['entry_serial', 'entry_number', 'entry_date', 'total_debit', 'created_at'])
            ->defaultSort('-entry_date')
            ->paginate();

        return $this->respondPaginated(JournalEntryResource::collection($entries));
    }

    /**
     * Show a journal entry
     *
     * Returns the lines three ways: flat, and split into the two columns an
     * accountant reads.
     *
     * @authenticated
     *
     * @urlParam journal_entry integer required The entry id. Example: 77
     *
     * @response 200 scenario="Success" {"data":{"id":77,"type":"journal_entry","entry_number":"JE-202609-0031","totals":{"debit":"40000.00","credit":"40000.00","balanced":true},"debit_lines":[{"account_id":12,"amount":"40000.00"}],"credit_lines":[{"account_id":31,"amount":"40000.00"}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function show(JournalEntry $journalEntry): JsonResponse
    {
        $this->authorize('view', $journalEntry);

        return $this->respond(new JournalEntryResource($this->loaded($journalEntry)));
    }

    /**
     * Open a draft entry
     *
     * The numbering is generated by the server. Lines may be sent here or
     * written afterwards; either way they go in as two columns.
     *
     * Nothing reaches the ledger until `post`.
     *
     * @authenticated
     *
     * @bodyParam document_type string required payment_order, supply_receipt or settlement. Example: settlement
     * @bodyParam entry_date date optional Defaults to today. Example: 2026-09-12
     * @bodyParam description string optional Example: Closing the cost centre of operation 2026-14
     * @bodyParam currency string optional Three letters, defaults to EGP. Example: EGP
     * @bodyParam debit_lines object[] optional The مدين column.
     * @bodyParam debit_lines[].account_id integer required Example: 12
     * @bodyParam debit_lines[].amount number required Example: 40000
     * @bodyParam debit_lines[].project_id integer optional The operation this line is charged to. Example: 4
     * @bodyParam debit_lines[].line_notes string optional Example: Busbar consumption
     * @bodyParam credit_lines object[] optional The دائن column, same shape.
     *
     * @response 201 scenario="Created" {"data":{"id":77,"type":"journal_entry","entry_number":"JE-202609-0031","status":{"value":"draft","label":"Draft","color":"gray"},"totals":{"balanced":true}},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function store(StoreJournalEntryRequest $request): JsonResponse
    {
        $this->authorize('create', JournalEntry::class);

        $documentType = DocumentType::from($request->string('document_type')->toString());

        $entry = JournalEntry::create([
            'entry_number' => JournalEntry::generateEntryNumber($documentType),
            'document_type' => $documentType,
            'entry_date' => $request->input('entry_date', now()->toDateString()),
            'description' => $request->input('description'),
            'currency' => $request->input('currency', 'EGP'),
            'notes' => $request->input('notes'),
            'status' => JournalStatus::Draft,
            'created_by' => Auth::id(),
        ]);

        if ($request->array('debit_lines') !== [] || $request->array('credit_lines') !== []) {
            $this->journals->syncLines(
                $entry,
                $request->array('debit_lines'),
                $request->array('credit_lines'),
            );
        }

        return $this->respondCreated(new JournalEntryResource($this->loaded($entry->fresh())));
    }

    /**
     * Update a draft entry's header
     *
     * Drafts only — the policy answers **403** on a posted entry.
     *
     * @authenticated
     *
     * @urlParam journal_entry integer required The entry id. Example: 77
     *
     * @bodyParam description string optional Example: Corrected description
     * @bodyParam entry_date date optional Example: 2026-09-13
     *
     * @response 200 scenario="Updated" {"data":{"id":77,"type":"journal_entry","description":"Corrected description"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function update(UpdateJournalEntryRequest $request, JournalEntry $journalEntry): JsonResponse
    {
        $this->authorize('update', $journalEntry);

        $journalEntry->update($request->validated());

        return $this->respond(new JournalEntryResource($this->loaded($journalEntry->fresh())));
    }

    /**
     * Write both sides of a draft entry
     *
     * Rows carrying an `id` are updated in place, new rows are created, and
     * rows left out are deleted — in one transaction, so a half-written entry
     * can never reach the books. Rows with no `account_id` are ignored, which
     * is what lets a client send the empty row a form always has at the
     * bottom.
     *
     * The totals are recomputed afterwards, so `totals.balanced` in the
     * response tells you straight away whether the entry can be posted.
     *
     * @authenticated
     *
     * @urlParam journal_entry integer required The entry id. Example: 77
     *
     * @bodyParam debit_lines object[] required The complete مدين column. Send `[]` to clear it.
     * @bodyParam debit_lines[].id integer optional The line to update; omit for a new one. Example: 140
     * @bodyParam debit_lines[].account_id integer required Example: 12
     * @bodyParam debit_lines[].amount number required Example: 40000
     * @bodyParam debit_lines[].project_id integer optional Example: 4
     * @bodyParam credit_lines object[] required The complete دائن column, same shape.
     *
     * @response 200 scenario="Written" {"data":{"id":77,"type":"journal_entry","totals":{"debit":"40000.00","credit":"40000.00","balanced":true}},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function replaceLines(ReplaceJournalEntryLinesRequest $request, JournalEntry $journalEntry): JsonResponse
    {
        $this->authorize('update', $journalEntry);

        $this->journals->syncLines(
            $journalEntry,
            $request->array('debit_lines'),
            $request->array('credit_lines'),
        );

        return $this->respond(new JournalEntryResource($this->loaded($journalEntry->fresh())));
    }

    /**
     * Post an entry to the ledger
     *
     * Enforces double entry: at least two lines, and total debits equal to
     * total credits. Both refusals name the figures, so the message can be
     * shown as written.
     *
     * After this the entry is immutable and appears in the trial balance, the
     * general ledger and every statement built on them.
     *
     * @authenticated
     *
     * @urlParam journal_entry integer required The entry id. Example: 77
     *
     * @response 200 scenario="Posted" {"data":{"id":77,"type":"journal_entry","status":{"value":"posted","label":"Posted","color":"success"},"posted_at":"2026-09-12T18:00:00+00:00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Unbalanced" {"error":{"code":"business_rule_violated","message":"Entry JE-202609-0031 is unbalanced: debit 40,000.00 vs credit 39,000.00."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function post(JournalEntry $journalEntry): JsonResponse
    {
        $this->authorize('post', $journalEntry);

        $this->journals->post($journalEntry);

        return $this->respond(new JournalEntryResource($this->loaded($journalEntry->fresh())));
    }

    /**
     * Delete a draft entry
     *
     * Drafts only — the policy answers **403** on a posted entry, because a
     * posted entry that could be deleted would take its movements out of the
     * trial balance with nothing left to say they ever existed.
     *
     * @authenticated
     *
     * @urlParam journal_entry integer required The entry id. Example: 77
     *
     * @response 204 scenario="Deleted" {}
     */
    public function destroy(JournalEntry $journalEntry): JsonResponse
    {
        $this->authorize('delete', $journalEntry);

        $journalEntry->delete();

        return $this->respondNoContent();
    }

    private function loaded(JournalEntry $entry): JournalEntry
    {
        return $entry->load(['lines.account'])->loadCount('lines');
    }
}
