<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Vault\Doctor\VaultDoctor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Diagnostic endpoint so `vault:doctor --fpm` can compare the FPM SAPI
 * against the CLI run.
 *
 * - `local`: reachable by an authenticated admin (browser) or by a validly
 *   signed URL (the `vault:doctor` command itself, which runs under a
 *   different SAPI and has no browser session to offer); full payload.
 * - anywhere else: 404 unless `vault.doctor_web_enabled` is on, and then a
 *   valid signature only — no session path — with the runtimeChecks()
 *   payload, which exposes php.ini values and no paths, keys or disk I/O.
 *
 * The environment check happens per-request (not at route-registration time)
 * so it stays live even though routes are loaded once at boot.
 */
final class VaultDoctorController extends Controller
{
    public function __invoke(Request $request, VaultDoctor $doctor): JsonResponse
    {
        $local = app()->environment('local');

        if ($local) {
            if (! $request->hasValidSignature()) {
                $user = $request->user();

                if (! $user || ! $user->hasRole('admin')) {
                    throw new HttpException(403, 'Forbidden.');
                }
            }

            $checks = $doctor->run();
        } else {
            abort_unless(config('vault.doctor_web_enabled') === true, 404);
            abort_unless($request->query->has('signature'), 404);
            abort_unless($request->hasValidSignature(), 403);

            $checks = $doctor->runtimeChecks();
        }

        return response()->json([
            'sapi' => PHP_SAPI,
            'checks' => $checks->map(fn ($check) => $check->toArray())->values(),
        ]);
    }
}
