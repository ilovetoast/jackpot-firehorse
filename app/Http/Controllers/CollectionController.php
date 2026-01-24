<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class CollectionController extends Controller
{
    /**
     * Show the collections page.
     */
    public function index(): Response
    {
        $user = Auth::user();
        $tenant = app('tenant');

        return Inertia::render('Collections/Index', [
            'collections' => [], // Empty for now - tmp page
        ]);
    }
}