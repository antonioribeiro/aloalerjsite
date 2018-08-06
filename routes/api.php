<?php

use Illuminate\Http\Request;

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

Route::group(['prefix' => 'v1'], function () {
    Route::get('zipcode/{zipcode}', 'Api\ZipCode@get')->name('api.zipcode.get');
});
