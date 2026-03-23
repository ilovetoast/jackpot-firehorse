<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class GenerativeController extends Controller
{
    /**
     * Show the generative page.
     */
    public function index(): Response
    {
        $user = Auth::user();
        $tenant = app('tenant');

        return Inertia::render('Editor/AssetEditor', []);
    }
}