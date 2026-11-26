<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\GymStore;
use Symfony\Component\HttpFoundation\{Request, Response, StreamedResponse};
use Symfony\Component\Routing\Attribute\Route;

final class ExerciseMediaController
{
    #[Route('/api/exercices/media/{id}', requirements: ['id' => '[a-f0-9]{64}'], methods: ['GET', 'HEAD'])]
    public function media(string $id, Request $request, GymStore $store): Response
    {
        if (!$store->isPostgreSql()) {
            return new Response('', 404);
        }
        $db = $store->db();
        $file = $db->fetchAssociative('SELECT mime, octet_length(content) AS bytes FROM exercise_media WHERE id = ?', [$id]);
        if (!$file) {
            return new Response('', 404);
        }

        $size = (int) $file['bytes'];
        $start = 0;
        $end = $size - 1;
        $partial = false;
        $headers = ['Accept-Ranges' => 'bytes', 'Content-Type' => $file['mime']];
        $range = $request->headers->get('Range', '');
        if (!$request->isMethod('HEAD') && !$request->headers->has('If-Range') &&
            preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $matches) &&
            ($matches[1] !== '' || $matches[2] !== '')) {
            $start = $matches[1] === '' ? max(0, $size - (int) $matches[2]) : (int) $matches[1];
            if ($matches[1] !== '' && $matches[2] !== '') {
                $end = min($end, (int) $matches[2]);
            }
            if ($start > $end) {
                return new Response('', 416, $headers + ['Content-Range' => "bytes */$size"]);
            }
            $partial = true;
            $headers['Content-Range'] = "bytes $start-$end/$size";
        }

        $headers['Content-Length'] = (string) ($end - $start + 1);
        if ($request->isMethod('HEAD')) {
            return new Response('', 200, $headers);
        }
        return new StreamedResponse(static function () use ($db, $id, $start, $end): void {
            for ($offset = $start; $offset <= $end; $offset += 1024 * 1024) {
                $length = min(1024 * 1024, $end - $offset + 1);
                $chunk = $db->fetchOne(
                    "SELECT encode(substring(content, ?, ?), 'base64') FROM exercise_media WHERE id = ?",
                    [$offset + 1, $length, $id],
                );
                echo base64_decode((string) $chunk, true);
            }
        }, $partial ? 206 : 200, $headers);
    }
}
