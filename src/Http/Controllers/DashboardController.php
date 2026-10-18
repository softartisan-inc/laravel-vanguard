<?php

namespace SoftArtisan\Vanguard\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

class DashboardController extends Controller
{
    /**
     * Serve the Vanguard SPA shell.
     *
     * Renders the minimal Blade layout that bootstraps the Vue 3 application.
     * All data is loaded by the frontend via the JSON API.
     *
     * @return View
     */
    public function index()
    {
        return view('vanguard::vanguard.layout');
    }
}
