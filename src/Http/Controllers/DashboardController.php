<?php

namespace SoftArtisan\Vanguard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Services\BackupManager;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Services\TenancyResolver;

class DashboardController extends Controller
{
    /**
     * Serve the Vanguard SPA shell.
     *
     * Renders the minimal Blade layout that bootstraps the Vue 3 application.
     * All data is loaded by the frontend via the JSON API.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function index()
    {
        return view('vanguard::vanguard.layout');
    }
}
