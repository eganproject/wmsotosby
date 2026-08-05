<?php

namespace App\Http\Requests\Admin;

class UpdateSupplierRequest extends StoreSupplierRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('suppliers.update');
    }
}
