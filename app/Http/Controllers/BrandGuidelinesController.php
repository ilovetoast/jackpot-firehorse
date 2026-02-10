<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class BrandGuidelinesController extends Controller
{
    /**
     * Temporary placeholder page for Brand Guidelines.
     */
    public function index(): Response
    {
        return Inertia::render('BrandGuidelines/Index');
    }
}
