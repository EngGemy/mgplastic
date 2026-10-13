<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FactoryCustomer extends Model
{
    protected $fillable = [
        'company_name',
        'contact_name',
        'phone',
        'address',
        'notes',
    ];

    public function priceLists(): HasMany
    {
        return $this->hasMany(PriceList::class);
    }

    public function optionLabel(): string
    {
        $label = $this->company_name;

        if (filled($this->contact_name)) {
            $label .= ' — '.$this->contact_name;
        }

        if (filled($this->phone)) {
            $label .= ' · '.$this->phone;
        }

        return $label;
    }
}
