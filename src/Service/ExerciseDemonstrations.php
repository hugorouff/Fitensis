<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

final class ExerciseDemonstrations
{
    private const DIRECTORY = __DIR__.'/../../data/demonstrations';
    public const FORMATS = ['thumbnails' => ['jpg', 'image/jpeg'], 'videos' => ['mp4', 'video/mp4']];

    public static function index(): array
    {
        return json_decode(file_get_contents(self::DIRECTORY.'/index.json'), true, 32, JSON_THROW_ON_ERROR);
    }

    public static function mediaId(string $key, string $type): string
    {
        return hash('sha256', 'fitensis-demo-v1/'.$key.'/'.$type);
    }

    public static function import(Connection $db): void
    {
        foreach (array_unique(self::index()) as $key) {
            foreach (self::FORMATS as $type => [$extension, $mime]) {
                $id = self::mediaId($key, $type);
                if ($db->fetchOne('SELECT 1 FROM exercise_media WHERE id = ?', [$id]) !== false) {
                    continue;
                }
                $content = file_get_contents(self::DIRECTORY.'/'.$key.'.'.$extension);
                if ($content === false || $content === '') {
                    throw new \RuntimeException('Démonstration manquante : '.$key);
                }
                $db->executeStatement(
                    "INSERT INTO exercise_media (id, mime, content) VALUES (?, ?, decode(?, 'base64')) ON CONFLICT (id) DO NOTHING",
                    [$id, $mime, base64_encode($content)]
                );
            }
        }
    }
}
