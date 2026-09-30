<?php

namespace App\Actions\Auth;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Logout (FND-004): audits the event with the actor still known, then ends the session.
 */
class Logout
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(Request $request): void
    {
        DB::transaction(function (): void {
            $this->audit->handle(AuditAction::Logout, Auth::user());
        });

        Auth::guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
