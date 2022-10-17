<?php

namespace App\Http\Controllers\V1;

use App\Enums\PeopleGroupTypeEnum;
use App\Enums\PeopleIsActiveEnum;
use App\Http\Controllers\Controller;
use App\Models\People;
use Illuminate\Http\Request;

class DepartmentUnitController extends Controller
{
    /**
     * West Java goverenment's units list
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request)
    {
        $limit = $request->input('limit', 20);
        $records = People::select('PeopleId as id', 'rolecode_sort as code', 'rolecode_name as name')
            ->leftJoin('role', 'people.PrimaryRoleId', '=', 'role.RoleId')
            ->rightJoin('rolecode', 'role.RoleCode', '=', 'rolecode.rolecode_id')
            ->where('GroupId', PeopleGroupTypeEnum::UK())
            ->where('PeopleIsActive', PeopleIsActiveEnum::ACTIVE())
            ->paginate($limit);
        return $records;
    }
}
