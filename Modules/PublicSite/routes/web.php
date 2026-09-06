<?php

use Illuminate\Support\Facades\Route;
use Modules\PublicSite\Http\Controllers\BuildingController;

/*
 * The public, read-only view of the building. No authentication: these pages
 * show where desks are, and nothing on them can be changed.
 */
Route::get('/', [BuildingController::class, 'index'])->name('building');
Route::get('/floors/{floor}', [BuildingController::class, 'floor'])->name('building.floor');
