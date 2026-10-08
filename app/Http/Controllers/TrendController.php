<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsMoneyInput;
use App\Services\MoneyTrendService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Tendances : ce mois-ci comparé à d'habitude, catégorie par catégorie. */
class TrendController extends Controller
{
    use ReadsMoneyInput;

    public function __invoke(Request $request, MoneyTrendService $trends): View
    {
        $scope = $this->scope($request);
        $data = $trends->compare($scope);

        return view('trends', [
            'scope' => $scope,
            'total' => $data['total'],
            'categories' => $data['categories'],
            'day' => $data['day'],
            'notable' => $trends->notable($scope, limit: 10),
            'yearAgo' => $trends->yearAgo($scope),
            'max' => max(1, (int) $data['categories']->max(fn ($row) => max($row['current'], $row['usual']))),
        ]);
    }
}
