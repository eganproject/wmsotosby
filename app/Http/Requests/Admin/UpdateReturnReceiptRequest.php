<?php

namespace App\Http\Requests\Admin;

use App\Models\ReturnReceipt;
use Illuminate\Validation\Rule;

class UpdateReturnReceiptRequest extends StoreReturnReceiptRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('returns.update');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        $isMarketplace = $this->input('type') === ReturnReceipt::TYPE_MARKETPLACE;

        // Nomor resi tetap unik, tapi dokumen ini sendiri dikecualikan.
        $rules['tracking_number'] = [
            Rule::requiredIf($isMarketplace),
            'nullable',
            'string',
            'max:100',
            Rule::unique('return_receipts', 'tracking_number')->ignore($this->route('return')->id),
        ];

        return $rules;
    }
}
