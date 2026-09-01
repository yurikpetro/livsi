<?php

namespace App\Http\Controllers;

class PageController extends Controller
{
    public function partners()
    {
        return view('pages.partners');
    }

    public function contractManufacturing()
    {
        return view('pages.contract-manufacturing');
    }
}