<?php

namespace App\Http\Controllers;

use App\Models\CreditTransaction;
use App\Services\Credits\CreditLedger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    /** Simulated top-up amounts, in USD. */
    public const PACKAGES = ['5', '10', '25', '50'];

    public function index(Request $request, CreditLedger $ledger): Response
    {
        $user = $request->user()->refresh();

        return Inertia::render('credits/Index', [
            'balance' => Money::normalize((string) $user->credit_balance),
            'reserved' => $ledger->reservedFor($user),
            'packages' => self::PACKAGES,
            'purchaseRequestId' => (string) Str::uuid(),
            'transactions' => CreditTransaction::query()
                ->where('user_id', $user->id)
                ->with('generation:id,uuid,prompt')
                ->orderByDesc('id')
                ->paginate(20)
                ->through(fn (CreditTransaction $t) => [
                    'reference' => $t->reference,
                    'type' => $t->type,
                    'amount' => Money::normalize($t->amount),
                    'balance_after' => Money::normalize($t->balance_after),
                    'description' => $t->description,
                    'shortfall' => $t->shortfall === null ? null : Money::normalize($t->shortfall),
                    'generation' => $t->generation ? ['id' => $t->generation->uuid, 'prompt' => $t->generation->prompt] : null,
                    'created_at' => $t->created_at?->toIso8601ZuluString(),
                ]),
        ]);
    }

    /**
     * Fake purchase for the assessment: adds credits, takes no payment.
     * The form's request id is the ledger reference, so a double submit
     * can't add the credits twice.
     */
    public function purchase(Request $request, CreditLedger $ledger): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'string', Rule::in(self::PACKAGES)],
            'purchase_request_id' => ['required', 'uuid'],
        ]);

        $existing = CreditTransaction::where('reference', $validated['purchase_request_id'])->first();
        abort_if($existing !== null && $existing->user_id !== $request->user()->id, 422);

        $ledger->purchase($request->user(), $validated['amount'], $validated['purchase_request_id']);

        return back()->with('success', "Added \${$validated['amount']}.00 in test credits.");
    }
}
