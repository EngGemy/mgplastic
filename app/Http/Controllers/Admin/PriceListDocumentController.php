<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PriceList;
use App\Models\WebsiteSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PriceListDocumentController extends Controller
{
    public function print(PriceList $priceList, Request $request): Response
    {
        $user = auth()->user();
        abort_unless($user && in_array($user->role, ['super_admin', 'admin'], true), 403);

        return $this->document($priceList, $request->boolean('auto'));
    }

    public function share(PriceList $priceList): Response
    {
        return $this->document($priceList, false);
    }

    private function document(PriceList $priceList, bool $autoPrint): Response
    {
        $priceList->load(['items' => fn ($query) => $query->orderBy('sort')->orderBy('id')]);

        return response()->view('price-lists.document', [
            'priceList' => $priceList,
            'phones' => WebsiteSetting::instance()->priceListPhones(),
            'autoPrint' => $autoPrint,
        ]);
    }
}
