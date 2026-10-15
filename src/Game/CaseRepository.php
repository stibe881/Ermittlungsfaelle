<?php
declare(strict_types=1);
namespace ColdCase\Game;

use ColdCase\Core\{Db, HttpError};

final class CaseRepository
{
    /** Veröffentlichte Fälle als Karten (spoilerfrei). */
    public static function listPublished(): array
    {
        $rows = Db::all("SELECT c.id,c.slug,c.case_number,cv.content FROM cases c JOIN case_versions cv ON cv.id=c.published_version_id WHERE c.status='published' ORDER BY c.case_number");
        return array_map(fn($r) => self::card($r, json_decode($r['content'], true)), $rows);
    }

    public static function card(array $row, array $content): array
    {
        $m = $content['meta'];
        return [
            'slug' => $row['slug'], 'case_number' => $row['case_number'], 'title' => $m['title'], 'teaser' => $m['teaser'],
            'location' => $m['location'], 'year' => $m['year'], 'difficulty' => $m['difficulty'], 'duration_min' => $m['duration_min'],
            'type' => $m['type'], 'suspects' => count($content['suspects']), 'cover' => $m['cover'] ?? null,
        ];
    }

    /** @return array{case:array,version:array,content:array} */
    public static function publishedBySlug(string $slug): array
    {
        $r = Db::one("SELECT c.id AS case_id,c.slug,c.case_number,cv.id AS version_id,cv.version,cv.content FROM cases c JOIN case_versions cv ON cv.id=c.published_version_id WHERE c.slug=? AND c.status='published'", [$slug]);
        if (!$r) throw new HttpError(404, 'case_not_found', 'Fall nicht gefunden.');
        return ['case' => ['id' => (int) $r['case_id'], 'slug' => $r['slug'], 'number' => $r['case_number']], 'version' => ['id' => (int) $r['version_id'], 'version' => (int) $r['version']], 'content' => json_decode($r['content'], true)];
    }

    /** Beliebige Version (nur Admin-Testmodus). */
    public static function versionBySlug(string $slug, int $versionId): array
    {
        $r = Db::one('SELECT c.id AS case_id,c.slug,c.case_number,cv.id AS version_id,cv.version,cv.content FROM case_versions cv JOIN cases c ON c.id=cv.case_id WHERE c.slug=? AND cv.id=?', [$slug, $versionId]);
        if (!$r) throw new HttpError(404, 'version_not_found');
        return ['case' => ['id' => (int) $r['case_id'], 'slug' => $r['slug'], 'number' => $r['case_number']], 'version' => ['id' => (int) $r['version_id'], 'version' => (int) $r['version']], 'content' => json_decode($r['content'], true)];
    }

    public static function contentForVersion(int $versionId): array
    {
        $c = Db::val('SELECT content FROM case_versions WHERE id=?', [$versionId]);
        if (!$c) throw new HttpError(500, 'version_missing');
        return json_decode($c, true);
    }

    /** Legt eine neue Version an (Entwurf). Gibt version_id zurück. */
    public static function saveVersion(string $slug, array $content, ?int $userId): array
    {
        $report = CaseValidator::validate($content);
        return Db::tx(function () use ($slug, $content, $userId, $report) {
            $case = Db::one('SELECT id FROM cases WHERE slug=? FOR UPDATE', [$slug]);
            if (!$case) {
                $id = Db::insert("INSERT INTO cases (slug,case_number,status,created_at) VALUES (?,?, 'draft', ?)", [$slug, $content['meta']['case_number'] ?? '000', Db::now()]);
            } else $id = (int) $case['id'];
            $next = (int) Db::val('SELECT COALESCE(MAX(version),0)+1 FROM case_versions WHERE case_id=?', [$id]);
            $json = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $vid = Db::insert('INSERT INTO case_versions (case_id,version,content,content_hash,validation_report,validation_ok,created_by,created_at) VALUES (?,?,?,?,?,?,?,?)',
                [$id, $next, $json, hash('sha256', $json), json_encode($report, JSON_UNESCAPED_UNICODE), $report['ok'] ? 1 : 0, $userId, Db::now()]);
            Db::exec('UPDATE cases SET case_number=? WHERE id=?', [$content['meta']['case_number'] ?? '000', $id]);
            return ['case_id' => $id, 'version_id' => $vid, 'version' => $next, 'report' => $report];
        });
    }

    public static function publish(string $slug, int $versionId): void
    {
        $v = Db::one('SELECT cv.id,cv.validation_ok,cv.case_id FROM case_versions cv JOIN cases c ON c.id=cv.case_id WHERE cv.id=? AND c.slug=?', [$versionId, $slug]);
        if (!$v) throw new HttpError(404, 'version_not_found');
        if (!(int) $v['validation_ok']) throw new HttpError(422, 'validation_failed', 'Der Fall besteht die Validierung nicht und kann nicht veröffentlicht werden.');
        Db::tx(function () use ($v) {
            Db::exec('UPDATE case_versions SET published_at=? WHERE id=?', [Db::now(), $v['id']]);
            Db::exec("UPDATE cases SET status='published', published_version_id=? WHERE id=?", [$v['id'], $v['case_id']]);
        });
    }
}
