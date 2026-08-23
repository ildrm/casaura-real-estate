<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PrincipalResource;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): PrincipalResource
    {
        return new PrincipalResource($request->user());
    }
}
