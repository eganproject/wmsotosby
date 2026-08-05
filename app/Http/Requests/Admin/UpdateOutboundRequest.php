<?php

namespace App\Http\Requests\Admin;

use App\Models\Outbound;
use Illuminate\Validation\Rule;

class UpdateOutboundRequest extends StoreOutboundRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('outbounds.update');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        $isMarketplace = $this->input('type') === Outbound::TYPE_MARKETPLACE;

        // Nomor resi tetap unik, tapi dokumen ini sendiri dikecualikan.
        $rules['tracking_number'] = [
            Rule::requiredIf($isMarketplace),
            'nullable',
            'string',
            'max:100',
            Rule::unique('outbounds', 'tracking_number')->ignore($this->route('outbound')->id),
        ];

        return $rules;
    }
}
