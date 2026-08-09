<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentTypeResource;
use App\Models\AppointmentType;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AppointmentTypeController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $types = AppointmentType::query()
            ->patientVisible()
            ->orderBy('name')
            ->get();

        return AppointmentTypeResource::collection($types);
    }
}
