<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// phpHelpers/upload.helper.php
// Generic upload + timeline helper for Tinuum webapp
//
// Responsibilities:
//   - Image metadata extraction (taken_at + GPS)
//   - Timeline entry create/update/delete
//   - Timeline media insert
//
// This helper is intentionally framework-agnostic: it does NOT
// start sessions, send headers, or emit HTML. Callers are
// responsible for CSRF, auth, redirects, and UI.

if (!function_exists('upload_exif_to_decimal')) {
    // ----------------------- Metadata helpers -----------------------

    function upload_exif_to_decimal($coord, $ref) {
        if (!is_array($coord) || count($coord) < 3) return null;
        $toFloat = function($v){
            if (is_string($v) && strpos($v,'/') !== false) {
                [$n,$d] = array_pad(explode('/',$v,2),2,1);
                return (float)$n/(float)$d;
            }
            return (float)$v;
        };
        $deg = $toFloat($coord[0]);
        $min = $toFloat($coord[1]);
        $sec = $toFloat($coord[2]);
        $sign = ($ref === 'S' || $ref === 'W') ? -1.0 : 1.0;
        return $sign * ($deg + $min/60.0 + $sec/3600.0);
    }

    function upload_dt_norm_to_sql($s) {
        try {
            $s = trim($s);
            if (preg_match('/^\d{4}:\d{2}:\d{2}\s+\d{2}:\d{2}:\d{2}$/', $s)) {
                $s = str_replace(':', '-', substr($s,0,10)) . substr($s,10);
            }
            $s = str_replace('T', ' ', $s);
            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:sP', $s)
               ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $s);
            if ($dt) return $dt->format('Y-m-d H:i:s');
        } catch (Throwable $e) {}
        return null;
    }

    function upload_dms_to_dec($s, $ref = null) {
        $s = trim($s);
        if (preg_match('/^-?\d+(?:\.\d+)?$/', $s)) {
            $val = (float)$s;
        } else {
            $s = str_replace(['°', "'", '"'], [' ', ' ', ' '], $s);
            $parts = preg_split('/[,\s]+/', trim($s));
            $d   = (float)($parts[0] ?? 0);
            $m   = (float)($parts[1] ?? 0);
            $sec = (float)($parts[2] ?? 0);
            $val = $d + $m/60 + $sec/3600;
        }
        if ($ref && in_array(strtoupper($ref), ['S','W'], true)) {
            $val = -abs($val);
        }
        return $val;
    }

    function upload_parse_xmp_datetime($xmp) {
        $patterns = [
            '/<exif:DateTimeOriginal>([^<]+)<\/exif:DateTimeOriginal>/i',
            '/<xmp:CreateDate>([^<]+)<\/xmp:CreateDate>/i',
            '/<exif:DateTimeDigitized>([^<]+)<\/exif:DateTimeDigitized>/i',
        ];

        foreach ($patterns as $re) {
            if (preg_match($re, $xmp, $m)) {
                $sql = upload_dt_norm_to_sql($m[1]);
                if ($sql) return $sql;
            }
        }
        return null;
    }

    function upload_parse_xmp_gps($xmp) {
        $lat = $lon = null; $latRef = $lonRef = null;
        if (preg_match('/<exif:GPSLatitude>([^<]+)<\/exif:GPSLatitude>/i', $xmp, $m)) $lat = $m[1];
        if (preg_match('/<exif:GPSLatitudeRef>([^<]+)<\/exif:GPSLatitudeRef>/i', $xmp, $m)) $latRef = $m[1];
        if (preg_match('/<exif:GPSLongitude>([^<]+)<\/exif:GPSLongitude>/i', $xmp, $m)) $lon = $m[1];
        if (preg_match('/<exif:GPSLongitudeRef>([^<]+)<\/exif:GPSLongitudeRef>/i', $xmp, $m)) $lonRef = $m[1];
        if ($lat !== null && $lon !== null) {
            $lat = upload_dms_to_dec($lat, $latRef);
            $lon = upload_dms_to_dec($lon, $lonRef);
            if (is_finite($lat) && is_finite($lon)) return ['lat'=>$lat,'lon'=>$lon];
        }
        return ['lat'=>null,'lon'=>null];
    }

    function upload_read_png_time_chunk($filePath) {
        $f = @fopen($filePath, 'rb');
        if (!$f) return null;
        try {
            fread($f, 8); // signature
            while (!feof($f)) {
                $lenData = fread($f, 4);
                $type    = fread($f, 4);
                if (strlen($lenData) < 4 || strlen($type) < 4) break;
                $len = unpack('N', $lenData)[1];
                if ($type === "tIME") {
                    $data = fread($f, $len);
                    if (strlen($data) === 7) {
                        $p = unpack('nyear/Cmonth/Cday/Chour/Cmin/Csec', $data);
                        fclose($f);
                        return sprintf('%04d-%02d-%02d %02d:%02d:%02d',
                            $p['year'],$p['month'],$p['day'],$p['hour'],$p['min'],$p['sec']
                        );
                    }
                    break;
                } else {
                    fseek($f, $len + 4, SEEK_CUR); // skip data+CRC
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
        fclose($f);
        return null;
    }

    function upload_parse_datetime_from_filename($name) {
        $name = basename($name);
        $cands = [
            '/(20\d{2})[-_]?(\d{2})[-_]?(\d{2})[ _-]?(\d{2})[:\-_.]?(\d{2})[:\-_.]?(\d{2})/',
            '/(20\d{2})[-_](\d{2})[-_](\d{2})[ T](\d{2})\.(\d{2})\.(\d{2})/',
        ];
        foreach ($cands as $re) {
            if (preg_match($re, $name, $m)) {
                return sprintf('%04d-%02d-%02d %02d:%02d:%02d',
                    $m[1],$m[2],$m[3],$m[4],$m[5],$m[6]
                );
            }
        }
        return null;
    }

    /**
     * Read metadata (time + GPS) for many formats:
     * - JPEG: EXIF Original -> Digitized + GPS via exif_read_data
     * - Others (PNG/WEBP/GIF/...): EXIF via Imagick exif:* (PNG eXIf),
     *   then XMP profile, then date:create/date:modify, then PNG tIME, then filename.
     *
     * Returns ['taken_at'=>Y-m-d H:i:s|null, 'lat'=>float|null, 'lon'=>float|null]
     */
    function upload_read_media_meta($filePath, $mime) {
        $out = ['lat'=>null,'lon'=>null,'taken_at'=>null];

        // JPEG first: EXIF via exif_read_data
        if (function_exists('exif_read_data') &&
            (stripos($mime,'jpeg') !== false || stripos($mime,'jpg') !== false)) {
            try {
                $exif = @exif_read_data($filePath, 'EXIF', true, false);
                if ($exif && is_array($exif)) {
                    $dto = $exif['EXIF']['DateTimeOriginal']  ?? null;
                    $dtz = $exif['EXIF']['DateTimeDigitized'] ?? null;
                    $off = $exif['EXIF']['OffsetTimeOriginal']
                        ?? ($exif['EXIF']['OffsetTimeDigitized'] ?? null);
                    $pick = $dto ?: $dtz;
                    if ($pick) {
                        $iso = str_replace(':', '-', substr($pick, 0, 10)) . substr($pick, 10);
                        if ($off && preg_match('/^[+-]\d{2}:\d{2}$/', $off)) {
                            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:sP', $iso.$off);
                        } else {
                            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $iso);
                        }
                        if ($dt) {
                            $out['taken_at'] = $dt->format('Y-m-d H:i:s');
                        }
                    }
                    $gps = $exif['GPS'] ?? null;
                    if (is_array($gps) &&
                        isset($gps['GPSLatitude'], $gps['GPSLatitudeRef'],
                              $gps['GPSLongitude'], $gps['GPSLongitudeRef'])) {
                        $lat = upload_exif_to_decimal($gps['GPSLatitude'],  $gps['GPSLatitudeRef']  ?? 'N');
                        $lon = upload_exif_to_decimal($gps['GPSLongitude'], $gps['GPSLongitudeRef'] ?? 'E');
                        if (is_finite($lat) && is_finite($lon)) {
                            $out['lat'] = $lat;
                            $out['lon'] = $lon;
                        }
                    }
                }
            } catch (Throwable $e) {
                // ignore
            }
            return $out;
        }

        // Non-JPEG: use Imagick (exif:*, XMP, then generic props)
        if (class_exists('Imagick')) {
            try {
                $im = new Imagick($filePath);

                // EXIF via Imagick properties (e.g. PNG eXIf)
                $exifDTO = $im->getImageProperty('exif:DateTimeOriginal');
                $exifDTZ = $im->getImageProperty('exif:DateTimeDigitized');
                $pick    = $exifDTO ?: $exifDTZ;
                if ($pick && !$out['taken_at']) {
                    $dt = upload_dt_norm_to_sql($pick);
                    if ($dt) $out['taken_at'] = $dt;
                }
                $exifLat  = $im->getImageProperty('exif:GPSLatitude');
                $exifLatR = $im->getImageProperty('exif:GPSLatitudeRef');
                $exifLon  = $im->getImageProperty('exif:GPSLongitude');
                $exifLonR = $im->getImageProperty('exif:GPSLongitudeRef');
                if (($exifLat !== '' && $exifLon !== '') &&
                    ($out['lat']===null || $out['lon']===null)) {
                    $lat = upload_dms_to_dec($exifLat,  $exifLatR ?: null);
                    $lon = upload_dms_to_dec($exifLon,  $exifLonR ?: null);
                    if (is_finite($lat) && is_finite($lon)) {
                        $out['lat'] = $lat;
                        $out['lon'] = $lon;
                    }
                }

                // XMP profile
                if (!$out['taken_at'] || $out['lat']===null || $out['lon']===null) {
                    $profiles = $im->getImageProfiles('xmp', true);
                    if (!empty($profiles['xmp'])) {
                        $xmp = $profiles['xmp'];
                        if (!$out['taken_at']) {
                            $dtX = upload_parse_xmp_datetime($xmp);
                            if ($dtX) $out['taken_at'] = $dtX;
                        }
                        if ($out['lat']===null || $out['lon']===null) {
                            $gps = upload_parse_xmp_gps($xmp);
                            if ($gps['lat'] !== null && $gps['lon'] !== null) {
                                $out['lat'] = $gps['lat'];
                                $out['lon'] = $gps['lon'];
                            }
                        }
                    }
                }

                // Generic times (creation/modify)
                if ($out['taken_at'] === null) {
                    $dc  = $im->getImageProperty('date:create');
                    $dm  = $im->getImageProperty('date:modify');
                    $try = $dc ?: $dm;
                    if ($try) {
                        $dt = upload_dt_norm_to_sql($try);
                        if ($dt) $out['taken_at'] = $dt;
                    }
                }
            } catch (Throwable $e) {
                // ignore
            }
        }

        // PNG tIME fallback
        if ($out['taken_at'] === null && stripos($mime, 'png') !== false) {
            $t = upload_read_png_time_chunk($filePath);
            if ($t) $out['taken_at'] = $t;
        }

        // Filename fallback
        if ($out['taken_at'] === null) {
            $fromName = upload_parse_datetime_from_filename($filePath);
            if ($fromName) $out['taken_at'] = $fromName;
        }

        return $out;
    }

    // ----------------------- DB helpers -----------------------

    function upload_fetch_fosc(PDO $pdo, int $foscId): ?array {
        $st = $pdo->prepare("
            SELECT f.fosc_id, f.splice_num, topology.ST_AsText(f.location) AS wkt,
                   topology.ST_X(f.location) AS lon, topology.ST_Y(f.location) AS lat
              FROM app.fosc f
             WHERE f.fosc_id = :id
        ");
        $st->execute([':id' => $foscId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    function upload_fetch_entry(PDO $pdo, int $id): ?array {
        $st = $pdo->prepare("SELECT * FROM app.timeline_entry WHERE timeline_entry_id = :id");
        $st->execute([':id' => $id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /**
     * Validate uploaded files (from $_FILES['images']) and extract per-file metadata.
     *
     * Returns array:
     *   ['uploads'=>[...], 'errors'=>[...], 'entry_taken_at'=>?string, 'entry_lat'=>?float, 'entry_lon'=>?float]
     */
    function upload_process_files(array $files,
                                  int $maxBytes = 20971520,
                                  array $allowedMimes = [
                                      'image/jpeg','image/jpg','image/png','image/webp',
                                      'image/gif','image/bmp','image/tiff','image/heic','image/heif'
                                  ]): array
    {
        $uploads      = [];
        $errors       = [];
        $entryTime    = null;
        $entryLat     = null;
        $entryLon     = null;

        if (!is_array($files['name'] ?? null)) {
            return [
                'uploads'        => [],
                'errors'         => ['No files provided or wrong field name (expected images[]).'],
                'entry_taken_at' => null,
                'entry_lat'      => null,
                'entry_lon'      => null,
            ];
        }

        $n = count($files['name']);
        for ($i = 0; $i < $n; $i++) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;

            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                $errors[] = 'Upload error: ' . ($files['name'][$i] ?? '');
                continue;
            }

            $tmp  = $files['tmp_name'][$i] ?? '';
            $size = @filesize($tmp);
            if ($size === false || $size <= 0) {
                $errors[] = 'Bad file size.';
                continue;
            }
            if ($size > $maxBytes) {
                $errors[] = 'Image exceeds max size: ' . ($files['name'][$i] ?? '');
                continue;
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($tmp) ?: 'application/octet-stream';

            if (!in_array(strtolower($mime), $allowedMimes, true) &&
                stripos($mime, 'image/') !== 0) {
                $errors[] = 'Unsupported image type: ' . ($files['name'][$i] ?? '') . ' (' . $mime . ')';
                continue;
            }

            $dims = @getimagesize($tmp);
            $w    = $dims[0] ?? null;
            $h    = $dims[1] ?? null;

            $meta    = upload_read_media_meta($tmp, $mime);
            $takenAt = $meta['taken_at'] ?? null;
            $ilat    = $meta['lat'] ?? null;
            $ilon    = $meta['lon'] ?? null;

            if ($takenAt) {
                if ($entryTime === null || $takenAt < $entryTime) $entryTime = $takenAt;
            }
            if ($entryLat === null && $ilat !== null && $ilon !== null) {
                $entryLat = $ilat;
                $entryLon = $ilon;
            }

            $uploads[] = [
                'tmp'      => $tmp,
                'name'     => $files['name'][$i],
                'mime'     => $mime,
                'width'    => $w,
                'height'   => $h,
                'taken_at' => $takenAt,
            ];
        }

        return [
            'uploads'        => $uploads,
            'errors'         => $errors,
            'entry_taken_at' => $entryTime,
            'entry_lat'      => $entryLat,
            'entry_lon'      => $entryLon,
        ];
    }

    /**
     * Create or update a timeline_entry + timeline_media rows.
     *
     * Args array keys:
     *   - id           (int)  existing timeline_entry_id or 0 for insert
     *   - fosc         (?array) ['fosc_id'=>int, 'splice_num'=>string] or null
     *   - parent_type  (?string) generic mode
     *   - parent_id    (?string) generic mode
     *   - body_text    (?string)
     *   - lat, lon     (?float|string) manual generic coordinates
     *   - exif_time    (?string) from upload_process_files
     *   - exif_lat     (?float)
     *   - exif_lon     (?float)
     *   - uploads      (array)  from upload_process_files['uploads']
     *
     * Returns: ['entry_id'=>int|null, 'errors'=>string[]]
     */
    function upload_save_timeline_entry(PDO $pdo, array $args): array {
    $id         = (int)($args['id'] ?? 0);
    $fosc       = $args['fosc'] ?? null;
    $parentType = $args['parent_type'] ?? '';
    $parentId   = $args['parent_id'] ?? '';
    $bodyText   = trim((string)($args['body_text'] ?? ''));

    $lat        = $args['lat'] ?? '';
    $lon        = $args['lon'] ?? '';

    $exifTime   = $args['exif_time'] ?? null;
    $exifLat    = $args['exif_lat'] ?? null;
    $exifLon    = $args['exif_lon'] ?? null;
    $uploads    = $args['uploads'] ?? [];

    $errors       = [];
    $boundFoscId  = ($fosc && isset($fosc['fosc_id'])) ? (int)$fosc['fosc_id'] : 0;

    // Validation: if not bound to a closure, we need a generic parent.
    if (!$boundFoscId) {
        if ($parentType === '') $errors[] = 'parent_type is required.';
        if ($parentId   === '') $errors[] = 'parent_id is required.';
    }

    if ($errors) {
        return ['entry_id' => null, 'errors' => $errors];
    }

    try {
        $pdo->beginTransaction();
        $entryId = $id > 0 ? $id : 0;

        if ($id > 0) {
            // ---------------- UPDATE ----------------
            if ($boundFoscId) {
                // FOSC-bound UPDATE: use fosc_id for both fosc_id and parent_id
                $geomSql = ($exifLat !== null && $exifLon !== null)
                    ? "ST_SetSRID(ST_MakePoint(:exif_lon::double precision,:exif_lat::double precision),4326)"
                    : "(SELECT location FROM app.fosc WHERE fosc_id = :fosc_id)";
                $timeSet = $exifTime ? ", created_at = :exif_time" : "";

                $sql = "
                    UPDATE app.timeline_entry
                       SET fosc_id     = :fosc_id,
                           parent_type = 'fosc',
                           parent_id   = :parent_id,
                           body_text   = :body,
                           geom        = $geomSql
                           $timeSet,
                           updated_at  = now()
                     WHERE timeline_entry_id = :id
                ";
                $st = $pdo->prepare($sql);
                $st->bindValue(':fosc_id',   $boundFoscId, PDO::PARAM_INT);
                $st->bindValue(':parent_id', $boundFoscId, PDO::PARAM_INT);
                if ($bodyText !== '') {
                    $st->bindValue(':body', $bodyText, PDO::PARAM_STR);
                } else {
                    $st->bindValue(':body', null, PDO::PARAM_NULL);
                }
                $st->bindValue(':id', $id, PDO::PARAM_INT);
                if ($exifLat !== null) {
                    $st->bindValue(':exif_lat', $exifLat);
                    $st->bindValue(':exif_lon', $exifLon);
                }
                if ($exifTime) {
                    $st->bindValue(':exif_time', $exifTime);
                }
                $st->execute();
            } else {
                // Generic UPDATE (not bound to closure)
                $pieces = [
                    "parent_type = :pt",
                    "parent_id   = :pid",
                    "body_text   = :body",
                    "updated_at  = now()"
                ];
                $params = [
                    ':pt'   => $parentType,
                    ':pid'  => $parentId,
                    ':body' => $bodyText !== '' ? $bodyText : null,
                    ':id'   => $id,
                ];

                if ($exifLat !== null && $exifLon !== null) {
                    $pieces[] = "geom = ST_SetSRID(ST_MakePoint(:exif_lon::double precision,:exif_lat::double precision),4326)";
                    $params[':exif_lat'] = $exifLat;
                    $params[':exif_lon'] = $exifLon;
                } elseif ($lat !== '' && $lon !== '' && is_numeric($lat) && is_numeric($lon)) {
                    $pieces[] = "geom = ST_SetSRID(ST_MakePoint(:lon::double precision,:lat::double precision),4326)";
                    $params[':lat'] = (float)$lat;
                    $params[':lon'] = (float)$lon;
                } else {
                    $pieces[] = "geom = NULL";
                }
                if ($exifTime) {
                    $pieces[] = "created_at = :exif_time";
                    $params[':exif_time'] = $exifTime;
                }

                $sql = "UPDATE app.timeline_entry SET " . implode(", ", $pieces) . " WHERE timeline_entry_id = :id";
                $st  = $pdo->prepare($sql);
                foreach ($params as $k => $v) {
                    if ($k === ':id') {
                        $st->bindValue($k, (int)$v, PDO::PARAM_INT);
                    } elseif ($k === ':body' && $v === null) {
                        $st->bindValue($k, null, PDO::PARAM_NULL);
                    } else {
                        $st->bindValue($k, $v);
                    }
                }
                $st->execute();
            }

            $entryId = $id;

        } else {
            // ---------------- INSERT ----------------
            if ($boundFoscId) {
                // FOSC-bound INSERT: fosc_id + parent_id = fosc_id
                $geomSql = ($exifLat !== null && $exifLon !== null)
                    ? "ST_SetSRID(ST_MakePoint(:exif_lon::double precision,:exif_lat::double precision),4326)"
                    : "(SELECT location FROM app.fosc WHERE fosc_id = :fosc_id)";

                $timeCols = $exifTime ? ", created_at" : "";
                $timeVals = $exifTime ? ", :exif_time" : "";

                $sql = "
                    INSERT INTO app.timeline_entry
                        (fosc_id, parent_type, parent_id, body_text, geom{$timeCols})
                    VALUES
                        (:fosc_id, 'fosc', :parent_id, :body, {$geomSql}{$timeVals})
                    RETURNING timeline_entry_id
                ";
                $st = $pdo->prepare($sql);
                $st->bindValue(':fosc_id',   $boundFoscId, PDO::PARAM_INT);
                $st->bindValue(':parent_id', $boundFoscId, PDO::PARAM_INT);
                if ($bodyText !== '') {
                    $st->bindValue(':body', $bodyText, PDO::PARAM_STR);
                } else {
                    $st->bindValue(':body', null, PDO::PARAM_NULL);
                }
                if ($exifLat !== null) {
                    $st->bindValue(':exif_lat', $exifLat);
                    $st->bindValue(':exif_lon', $exifLon);
                }
                if ($exifTime) {
                    $st->bindValue(':exif_time', $exifTime);
                }
                $st->execute();
                $entryId = (int)$st->fetchColumn();

            } else {
                // Generic INSERT
                $geomSql = "NULL";
                $params  = [];

                if ($exifLat !== null && $exifLon !== null) {
                    $geomSql = "ST_SetSRID(ST_MakePoint(:exif_lon::double precision,:exif_lat::double precision),4326)";
                    $params[':exif_lat'] = $exifLat;
                    $params[':exif_lon'] = $exifLon;
                } elseif ($lat !== '' && $lon !== '' && is_numeric($lat) && is_numeric($lon)) {
                    $geomSql = "ST_SetSRID(ST_MakePoint(:lon::double precision,:lat::double precision),4326)";
                    $params[':lat'] = (float)$lat;
                    $params[':lon'] = (float)$lon;
                }

                $timeCols = $exifTime ? ", created_at" : "";
                $timeVals = $exifTime ? ", :exif_time" : "";

                // A closure's note also records the closure itself (fosc_id), as the closure page does.
                $foscCol = $foscVal = '';
                if ($parentType === 'fosc' && ctype_digit((string)$parentId)) {
                    $foscCol = ', fosc_id'; $foscVal = ', :fid';
                    $params[':fid'] = (int)$parentId;
                    if ($geomSql === 'NULL') { $geomSql = '(SELECT location FROM app.fosc WHERE fosc_id = :fid2)'; $params[':fid2'] = (int)$parentId; }
                } elseif ($parentType === 'pop' && ctype_digit((string)$parentId) && $geomSql === 'NULL') {
                    $geomSql = '(SELECT location FROM app.pop WHERE pop_id = :popid)';
                    $params[':popid'] = (int)$parentId;
                }
                $sql = "
                    INSERT INTO app.timeline_entry
                        (parent_type, parent_id, body_text, geom{$timeCols}{$foscCol})
                    VALUES
                        (:pt, :pid, :body, {$geomSql}{$timeVals}{$foscVal})
                    RETURNING timeline_entry_id
                ";
                $st = $pdo->prepare($sql);
                $st->bindValue(':pt',  $parentType);
                $st->bindValue(':pid', $parentId);
                if ($bodyText !== '') {
                    $st->bindValue(':body', $bodyText, PDO::PARAM_STR);
                } else {
                    $st->bindValue(':body', null, PDO::PARAM_NULL);
                }
                foreach ($params as $k => $v) {
                    $st->bindValue($k, $v);
                }
                if ($exifTime) {
                    $st->bindValue(':exif_time', $exifTime);
                }
                $st->execute();
                $entryId = (int)$st->fetchColumn();
            }
        }

        // Sanity check before inserting media
        if (!empty($uploads) && $entryId <= 0) {
            throw new RuntimeException('Timeline entry ID missing when saving media (got 0).');
        }

        // ---------------- MEDIA INSERTS ----------------
        if (!empty($uploads)) {
            $ins = $pdo->prepare("
                INSERT INTO app.timeline_media
                  (timeline_entry_id, blob, mime_type, file_name, width, height, exif_created_at)
                VALUES
                  (:eid, :blob, :mime, :name, :w, :h, :taken_at)
            ");
            foreach ($uploads as $u) {
                $fp = fopen($u['tmp'], 'rb');
                if ($fp === false) {
                    throw new RuntimeException('Open upload failed.');
                }
                $ins->bindValue(':eid',  $entryId,      PDO::PARAM_INT);
                $ins->bindValue(':blob', $fp,           PDO::PARAM_LOB);
                $ins->bindValue(':mime', $u['mime']);
                $ins->bindValue(':name', $u['name']);
                if ($u['width'] !== null) {
                    $ins->bindValue(':w', $u['width'], PDO::PARAM_INT);
                } else {
                    $ins->bindValue(':w', null, PDO::PARAM_NULL);
                }
                if ($u['height'] !== null) {
                    $ins->bindValue(':h', $u['height'], PDO::PARAM_INT);
                } else {
                    $ins->bindValue(':h', null, PDO::PARAM_NULL);
                }
                if (!empty($u['taken_at'])) {
                    $ins->bindValue(':taken_at', $u['taken_at']);
                } else {
                    $ins->bindValue(':taken_at', null, PDO::PARAM_NULL);
                }
                $ins->execute();
                if (is_resource($fp)) fclose($fp);
            }
        }

        $pdo->commit();
        return ['entry_id' => $entryId, 'errors' => []];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['entry_id' => null, 'errors' => ['DB error: ' . $e->getMessage()]];
    }
}

    /**
     * Delete a single timeline_entry (does NOT cascade media; rely on FK ON DELETE CASCADE).
     * Returns ['ok'=>bool, 'error'=>?string]
     */
    function upload_delete_timeline_entry(PDO $pdo, int $id): array {
        if ($id <= 0) {
            return ['ok' => false, 'error' => 'Invalid id.'];
        }
        try {
            $st = $pdo->prepare("DELETE FROM app.timeline_entry WHERE timeline_entry_id = :id");
            $st->execute([':id' => $id]);
            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delete a single media row by timeline_media_id.
     * Used by the timeline entry edit form when removing individual photos.
     *
     * Returns: ['ok' => bool, 'error' => ?string]
     */
    function timeline_delete_media( $pdo, int $mediaId): array
    {
        if ($mediaId <= 0) {
            return ['ok' => false, 'error' => 'Invalid media id.'];
        }

        try {
            $st = $pdo->prepare("
                DELETE FROM app.timeline_media
                 WHERE timeline_media_id = :id
            ");
            $st->execute([':id' => $mediaId]);
            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

}
