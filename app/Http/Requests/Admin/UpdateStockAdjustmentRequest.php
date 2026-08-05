<?php

namespace App\Http\Requests\Admin;

class UpdateStockAdjustmentRequest extends StoreStockAdjustmentRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('adjustments.update');
    }
}
