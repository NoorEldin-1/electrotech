<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountDirection;
use App\Enums\DocumentType;
use App\Enums\JournalStatus;
use App\Enums\PaymentDirection;
use App\Models\Account;
use App\Models\FinancialClaim;
use App\Models\JournalEntry;
use App\Models\OperationPayment;
use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * الدفعات النقدية والمقبوضات — records operation payments (سلايد 1) and,
 * optionally, posts the matching GL entry. Incoming payments can be allocated
 * to a financial claim and auto-collect it once fully paid.
 */
class OperationPaymentService
{
    public function __construct(private readonly JournalEntryService $journals)
    {
    }

    /**
     * Record a payment. When config('operations.auto_journal_payments') is on
     * and the GL accounts resolve, also posts a balanced journal entry.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(array $data): OperationPayment
    {
        return DB::transaction(function () use ($data) {
            $payment = OperationPayment::create([
                'project_id' => $data['project_id'],
                'customer_id' => $data['customer_id'] ?? null,
                'financial_claim_id' => $data['financial_claim_id'] ?? null,
                'direction' => $data['direction'],
                'method' => $data['method'],
                'account_id' => $data['account_id'] ?? null,
                'counter_account_id' => $data['counter_account_id'] ?? null,
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'EGP',
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            if (config('operations.auto_journal_payments')) {
                $this->postJournalFor($payment);
            }

            if ($payment->financial_claim_id) {
                $this->settleClaimIfFullyPaid($payment->financialClaim);
            }

            return $payment;
        });
    }

    /**
     * Link a payment to a claim and collect the claim once fully paid.
     */
    public function allocateToClaim(OperationPayment $payment, FinancialClaim $claim): void
    {
        $payment->update(['financial_claim_id' => $claim->id]);
        $this->settleClaimIfFullyPaid($claim->fresh());
    }

    /**
     * Cash totals for an operation. Received/paid drive the supply-orders file;
     * `received` is exposed to the Operation Cost Center as collected revenue.
     *
     * @return array{received: float, paid: float, net: float}
     */
    public function totalsForProject(Project $project): array
    {
        $received = (float) $project->operationPayments()
            ->where('direction', PaymentDirection::Incoming->value)
            ->sum('amount');

        $paid = (float) $project->operationPayments()
            ->where('direction', PaymentDirection::Outgoing->value)
            ->sum('amount');

        return [
            'received' => $received,
            'paid' => $paid,
            'net' => $received - $paid,
        ];
    }

    /**
     * Build and post the balanced GL entry for a payment, then link it back.
     * Skips silently if the accounts cannot be resolved.
     */
    private function postJournalFor(OperationPayment $payment): void
    {
        $treasury = $payment->account_id ? Account::find($payment->account_id) : null;

        if ($treasury === null) {
            return;
        }

        $counter = $payment->counter_account_id
            ? Account::find($payment->counter_account_id)
            : ($payment->isIncoming() ? $this->customersAccount() : null);

        if ($counter === null) {
            return;
        }

        // incoming: Dr treasury / Cr counter (customers). outgoing: reverse.
        [$debitAccount, $creditAccount, $type] = $payment->isIncoming()
            ? [$treasury, $counter, DocumentType::SupplyReceipt]
            : [$counter, $treasury, DocumentType::PaymentOrder];

        $entry = JournalEntry::create([
            'entry_number' => JournalEntry::generateEntryNumber($type),
            'document_type' => $type,
            'document_number' => $payment->payment_number,
            'entry_date' => $payment->payment_date,
            'description' => __('resources.operation_payments.journal_description', [
                'number' => $payment->payment_number,
            ]),
            'status' => JournalStatus::Draft,
            'currency' => $payment->currency,
            'created_by' => Auth::id(),
        ]);

        foreach ([
            [$debitAccount->id, AccountDirection::Debit],
            [$creditAccount->id, AccountDirection::Credit],
        ] as [$accountId, $direction]) {
            $entry->lines()->create([
                'account_id' => $accountId,
                'project_id' => $payment->project_id,
                'direction' => $direction,
                'amount' => $payment->amount,
            ]);
        }

        $this->journals->post($entry->fresh('lines'));

        $payment->update(['journal_entry_id' => $entry->id]);
    }

    private function settleClaimIfFullyPaid(?FinancialClaim $claim): void
    {
        if ($claim === null || ! $claim->isSubmitted()) {
            return;
        }

        $paid = (float) OperationPayment::query()
            ->where('financial_claim_id', $claim->id)
            ->where('direction', PaymentDirection::Incoming->value)
            ->sum('amount');

        if ($paid + 0.001 >= (float) $claim->amount) {
            app(FinancialClaimService::class)->collect($claim);
        }
    }

    private function customersAccount(): ?Account
    {
        return Account::where('code', config('operations.customers_account_code', '1200'))->first();
    }
}
