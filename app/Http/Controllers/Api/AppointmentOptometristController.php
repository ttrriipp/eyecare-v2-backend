<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentOptometristResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AppointmentOptometristController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $optometrists = User::query()
            ->optometrists()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return AppointmentOptometristResource::collection($optometrists);
    }
}
