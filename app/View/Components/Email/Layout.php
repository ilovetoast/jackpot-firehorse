<?php

namespace App\View\Components\Email;

use Illuminate\View\Component;

class Layout extends Component
{
    public $title;
    public $headerText;
    public $footerText;

    /**
     * Create a new component instance.
     */
    public function __construct($title = null, $headerText = null, $footerText = null)
    {
        $this->title = $title ?? config('app.name');
        $this->headerText = $headerText ?? config('app.name');
        $this->footerText = $footerText ?? '© ' . date('Y') . ' ' . config('app.name') . '. All rights reserved.';
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render()
    {
        return view('components.email.layout');
    }
}
