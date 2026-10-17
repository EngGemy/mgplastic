<?php

namespace App\Http\Controllers\Api\Trader;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Services\NetworkReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    use ApiResponds;

    public function __construct(protected NetworkReportService $reports) {}

    public function index(Request $request): JsonResponse
    {
        $data = $this->reports->forUser(
            $request->user(),
            $request->query('from'),
            $request->query('to'),
        );

        return $this->success($data, 'تقرير الفترة');
    }
}
