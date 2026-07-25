<?php

namespace App\Filament\Resources\Emails\Pages;

use App\Filament\Resources\Emails\EmailResource;
use Filament\Resources\Pages\ViewRecord;
use Native\Desktop\Facades\Shell;

class ViewEmail extends ViewRecord
{
    protected static string $resource = EmailResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->getRecord()->markAsRead();
    }

    protected function getHeaderActions(): array
    {
        return [
        ];
    }

    /**
     * Called from the sandboxed HTML preview (resources/views/filament/email-html-view.blade.php)
     * when the user clicks a link or button inside the rendered email. The iframe can't navigate
     * on its own, so it posts the URL up to this Livewire component instead, and we hand it to the
     * OS's default browser rather than opening it inside the app.
     *
     * Scheme is re-validated here (not just in the JS interceptor) since this method is a public
     * Livewire endpoint reachable from the page.
     */
    public function openExternalLink(string $url): void
    {
        if (! preg_match('/^(https?|mailto|tel):/i', $url)) {
            return;
        }

        Shell::openExternal($url);
    }
}
