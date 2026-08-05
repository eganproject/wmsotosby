<?php

namespace App\Http\Requests\Admin;

class UpdateInboundRequest extends StoreInboundRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inbounds.update');
    }
}
