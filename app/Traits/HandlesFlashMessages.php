<?php

namespace App\Traits;

use Illuminate\Http\RedirectResponse;
use Inertia\Response;

trait HandlesFlashMessages
{
    /**
     * Return a redirect response with a success flash message.
     * 
     * @param string $route The route name to redirect to
     * @param string $message The success message (default: 'Updated')
     * @return RedirectResponse
     */
    protected function redirectWithSuccess(string $route, string $message = 'Updated'): RedirectResponse
    {
        return redirect()->route($route)->with('success', $message);
    }

    /**
     * Return a back response with a success flash message.
     * 
     * @param string $message The success message (default: 'Updated')
     * @return RedirectResponse
     */
    protected function backWithSuccess(string $message = 'Updated'): RedirectResponse
    {
        return back()->with('success', $message);
    }

    /**
     * Return a redirect response with an error flash message.
     * 
     * @param string $route The route name to redirect to
     * @param string $message The error message
     * @return RedirectResponse
     */
    protected function redirectWithError(string $route, string $message): RedirectResponse
    {
        return redirect()->route($route)->with('error', $message);
    }

    /**
     * Return a back response with an error flash message.
     * 
     * @param string $message The error message
     * @return RedirectResponse
     */
    protected function backWithError(string $message): RedirectResponse
    {
        return back()->with('error', $message);
    }

    /**
     * Return a redirect response with a warning flash message.
     * 
     * @param string $route The route name to redirect to
     * @param string $message The warning message
     * @return RedirectResponse
     */
    protected function redirectWithWarning(string $route, string $message): RedirectResponse
    {
        return redirect()->route($route)->with('warning', $message);
    }

    /**
     * Return a back response with a warning flash message.
     * 
     * @param string $message The warning message
     * @return RedirectResponse
     */
    protected function backWithWarning(string $message): RedirectResponse
    {
        return back()->with('warning', $message);
    }

    /**
     * Return a redirect response with an info flash message.
     * 
     * @param string $route The route name to redirect to
     * @param string $message The info message
     * @return RedirectResponse
     */
    protected function redirectWithInfo(string $route, string $message): RedirectResponse
    {
        return redirect()->route($route)->with('info', $message);
    }

    /**
     * Return a back response with an info flash message.
     * 
     * @param string $message The info message
     * @return RedirectResponse
     */
    protected function backWithInfo(string $message): RedirectResponse
    {
        return back()->with('info', $message);
    }
}
