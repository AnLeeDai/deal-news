<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Routing\Redirector;

Route::get('/', function (Redirector $redirect) {
    return $redirect->to('/up');
});
