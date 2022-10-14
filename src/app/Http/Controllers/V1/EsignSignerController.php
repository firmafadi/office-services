<?php

namespace App\Http\Controllers\V1;

use App\Enums\PeopleIsActiveEnum;
use App\Http\Controllers\Controller;
use App\Models\People;
use Illuminate\Http\Request;

class EsignSignerController extends Controller
{
    /**
     * Signers list
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request)
    {
        $limit = $request->input('limit', 20);
        $records = People::select('NIP', 'PeopleName as name', 'PeoplePosition as position')
            ->whereNotNull('NIP')
            ->where('PeopleIsActive', PeopleIsActiveEnum::ACTIVE())
            ->paginate($limit);
        return $records;
    }
}
