<?php

namespace SoftArtisan\Vanguard\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class AssetsController extends Controller
{
    /**
     * Serve a compiled Vanguard asset (JS or CSS) directly from the package.
     *
     * Reads the file from the package's own public/ directory so that no
     * vendor:publish step is required for assets. Responds with proper
     * Content-Type and long-lived cache headers keyed on the file's mtime,
     * so browsers re-fetch automatically after a package upgrade.
     *
     * @param  Request  $request
     * @param  string   $file  'vanguard.js' or 'vanguard.css'
     * @return Response
     */
    public function serve(Request $request, string $file): Response
    {
        $allowed = ['vanguard.js', 'vanguard.css'];

        if (! in_array($file, $allowed, true)) {
            abort(404);
        }

        $path = realpath(__DIR__.'/../../../public/'.$file);

        if (! $path || ! file_exists($path)) {
            abort(404, "Vanguard asset [{$file}] not found. Run: pnpm build");
        }

        $mtime    = filemtime($path);
        $etag     = md5($mtime.$file);
        $mimeType = str_ends_with($file, '.css') ? 'text/css' : 'application/javascript';

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304);
        }

        return response(file_get_contents($path), 200, [
            'Content-Type'  => $mimeType,
            'ETag'          => $etag,
            'Cache-Control' => 'public, max-age=31536000',
            'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime).' GMT',
        ]);
    }
}
